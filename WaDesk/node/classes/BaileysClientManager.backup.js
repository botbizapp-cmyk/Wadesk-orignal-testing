// classes/BaileysClientManager.js
// ==============================
// Baileys Client Manager - Complete with QR & Pairing Code Support
// ==============================

import {
  makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  Browsers,
  makeInMemoryStore
} from "@itsukichan/baileys";
import P from "pino";
import { Boom } from "@hapi/boom";
import fs from "fs-extra";
import path from "path";
import { fileURLToPath } from "url";
import axios from "axios";
import qrcode from "qrcode-terminal";
import moment from "moment-timezone";
import { updateStatusInLaravel, formatPhoneNumber } from "../utils/helpers.js";
import { executeFlowNode, handleFlowResponse } from "../services/flowService.js";
import { handleCampaignMessageUpdate, trackCampaignResponse } from '../services/campaignService.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export class BaileysClientManager {
  constructor(phoneNumber, appLocals, appDomainName) {
    this.phoneNumber = phoneNumber;
    this.sock = null;
    this.logger = P({ level: "silent" });
    this.appLocals = appLocals;
    this.appDomainName = appDomainName;
    this.authFolder = path.join(
      __dirname,
      "..",
      "baileys_auth",
      `session_${phoneNumber}`
    );
    this.qrCode = null;
    this.pairingCode = null;  // For pairing code
    this.usePairingCode = false;  // Flag for pairing code mode
    this.connectionRetries = 0;
    this.maxRetries = 10;
    this.isQRScanned = false;
    this.lastQRTime = 0;
    this.nextConnectStatus = null;
    this.isReconnecting = false;
    this.shouldStayConnected = true;
    this.isConnecting = false;
    this.isConnected = false;
    
    // Store setup
    this.store = makeInMemoryStore({ 
      logger: this.logger 
    });
    
    this.storeFile = path.join(this.authFolder, 'baileys_store.json');
    
    // Initialize cooldown tracker if not exists
    if (!this.appLocals.userCooldowns) {
      this.appLocals.userCooldowns = {};
    }
  }

  async start() {
    // Prevent duplicate connection attempts
    if (this.isConnecting || this.isConnected) {
      console.log(`⚠️ Already connecting/connected for: ${this.phoneNumber}`);
      return this.sock;
    }

    this.isConnecting = true;
    
    console.log(`🚀 Starting client for: ${this.phoneNumber}`);
    try {
      await fs.ensureDir(this.authFolder);
      
      // Load store from file if exists
      if (await fs.pathExists(this.storeFile)) {
        try {
          this.store.readFromFile(this.storeFile);
        } catch (error) {
          console.log(`⚠️ Could not read store file, starting fresh`);
        }
      }
      
      // Save store every 10 seconds
      setInterval(() => {
        try {
          this.store.writeToFile(this.storeFile);
        } catch (error) {
          console.error("❌ Error saving store:", error);
        }
      }, 10_000); 
      
      console.log(`📁 Auth folder: ${this.authFolder}`);
      const { state, saveCreds } = await useMultiFileAuthState(this.authFolder);

      this.sock = makeWASocket({
        auth: state,
        printQRInTerminal: false,
        logger: this.logger,
        browser: Browsers.ubuntu("My Desktop"),
        connectTimeoutMs: 60000,
        defaultQueryTimeoutMs: 60000,
        keepAliveIntervalMs: 30000,
        retryRequestDelayMs: 3000,
        maxMsgRetryCount: 5,
        qrTimeout: 180000,
        connectCooldownMs: 0,
        emitOwnEvents: false,
        fireInitQueries: true,
        syncFullHistory: true,
        markOnlineOnConnect: false,
        transactionOpts: {
          maxCommitRetries: 10,
          delayBetweenTriesMs: 3000
        },
        getMessage: async (key) => {
          return { conversation: "Message not found" };
        },
        generateHighQualityLinkPreview: false,
        shouldIgnoreJid: (jid) => false,
        patchMessageBeforeSending: (message) => {
          return message;
        }
      });

      this.sock.phoneNumber = this.phoneNumber;
      this.store.bind(this.sock.ev);
      
      this.sock.ev.on("messages.update", async (updates) => {
        await this.handleMessageStatusUpdate(updates);
        await handleCampaignMessageUpdate(updates, this.appLocals, this.appDomainName);
      });

      // Save credentials with debouncing
      let savingCreds = false;
      this.sock.ev.on("creds.update", async () => {
        if (savingCreds) return;
        try {
          savingCreds = true;
          await saveCreds();
          console.log(`💾 Credentials saved for: ${this.phoneNumber}`);
        } catch (error) {
          console.error("❌ Error saving credentials:", error);
        } finally {
          setTimeout(() => { savingCreds = false; }, 1000);
        }
      });

      // Handle connection updates
      this.sock.ev.on("connection.update", (update) =>
        this.handleConnectionUpdate(update, saveCreds)
      );

      // Handle messages
      this.sock.ev.on("messages.upsert", async (m) => {
        const { messages, type } = m;
        try {
          // Track campaign responses for each incoming message first
          for (const message of messages) {
            if (!message.key.fromMe && message.message) {
              await trackCampaignResponse(message, this.appLocals, this.appDomainName);
            }
          }
          // Then handle messages normally (batch)
          await this.handleMessages({ messages, type });
        } catch (error) {
          console.error("❌ Error in messages.upsert handler:", error);
        }
      });

      this.appLocals.clients[this.phoneNumber] = this.sock;
      this.appLocals.client_ready[this.phoneNumber] = false;
      console.log(`✅ Client initialized for: ${this.phoneNumber}`);
      return this.sock;
    } catch (error) {
      this.isConnecting = false;
      console.error(`❌ Error starting client for ${this.phoneNumber}:`, error);
      if (this.shouldStayConnected && this.connectionRetries < this.maxRetries) {
        this.connectionRetries++;
        console.log(`🔄 Retrying start (${this.connectionRetries}/${this.maxRetries})...`);
        setTimeout(() => this.start(), 5000);
      }
    }
  }

  async startWithPairingCode() {
    this.usePairingCode = true;

        this.pairingCode = null;

    
    // Prevent duplicate connection attempts
    if (this.isConnecting || this.isConnected) {
      console.log(`⚠️ Already connecting/connected for: ${this.phoneNumber}`);
      return this.sock;
    }

    this.isConnecting = true;
    
    console.log(`🚀 Starting client with pairing code for: ${this.phoneNumber}`);
    
    try {
      await fs.ensureDir(this.authFolder);
      
      // Load store from file if exists
      if (await fs.pathExists(this.storeFile)) {
        try {
          this.store.readFromFile(this.storeFile);
        } catch (error) {
          console.log(`⚠️ Could not read store file, starting fresh`);
        }
      }
      
      // Save store every 10 seconds
      setInterval(() => {
        try {
          this.store.writeToFile(this.storeFile);
        } catch (error) {
          console.error("❌ Error saving store:", error);
        }
      }, 10_000);
      
      console.log(`📁 Auth folder: ${this.authFolder}`);
      const { state, saveCreds } = await useMultiFileAuthState(this.authFolder);

      this.sock = makeWASocket({
    auth: state,
    printQRInTerminal: false,
    logger: this.logger,
    browser: Browsers.ubuntu("My Desktop"),
    connectTimeoutMs: 60000,
    defaultQueryTimeoutMs: 60000,
    keepAliveIntervalMs: 30000,
    retryRequestDelayMs: 3000,
    maxMsgRetryCount: 5,
    qrTimeout: 180000,
    connectCooldownMs: 0,
    emitOwnEvents: false,
    fireInitQueries: true,

    // 🔥 MOST IMPORTANT FIX
    syncFullHistory: false,

    markOnlineOnConnect: false,
    transactionOpts: {
      maxCommitRetries: 10,
      delayBetweenTriesMs: 3000
    },
    getMessage: async (key) => ({ conversation: "Message not found" }),
    generateHighQualityLinkPreview: false,
});


      this.sock.phoneNumber = this.phoneNumber;
      this.store.bind(this.sock.ev);
      
      this.sock.ev.on("messages.update", async (updates) => {
        await this.handleMessageStatusUpdate(updates);
        await handleCampaignMessageUpdate(updates, this.appLocals, this.appDomainName);
      });

      // Save credentials with debouncing
      let savingCreds = false;
      this.sock.ev.on("creds.update", async () => {
        if (savingCreds) return;
        try {
          savingCreds = true;
          await saveCreds();
          console.log(`💾 Credentials saved for: ${this.phoneNumber}`);
        } catch (error) {
          console.error("❌ Error saving credentials:", error);
        } finally {
          setTimeout(() => { savingCreds = false; }, 1000);
        }
      });

      // Handle connection updates with pairing code support
      this.sock.ev.on("connection.update", async (update) => {
        await this.handleConnectionUpdateWithPairing(update, saveCreds);
      });

      // Handle messages
      this.sock.ev.on("messages.upsert", async (m) => {
        const { messages, type } = m;
        try {
          for (const message of messages) {
            if (!message.key.fromMe && message.message) {
              await trackCampaignResponse(message, this.appLocals, this.appDomainName);
            }
          }
          await this.handleMessages({ messages, type });
        } catch (error) {
          console.error("❌ Error in messages.upsert handler:", error);
        }
      });

      this.appLocals.clients[this.phoneNumber] = this.sock;
      this.appLocals.client_ready[this.phoneNumber] = false;
      console.log(`✅ Client initialized for pairing code: ${this.phoneNumber}`);
      
      return this.sock;
      
    } catch (error) {
      this.isConnecting = false;
      console.error(`❌ Error starting client with pairing code for ${this.phoneNumber}:`, error);
      throw error;
    }
  }

  async handleConnectionUpdate(update, saveCreds) {
    const { connection, lastDisconnect, qr, isNewLogin } = update;

    if (qr) {
      const now = Date.now();
      if (now - this.lastQRTime < 5000) {
        console.log(`⏭️ Skipping duplicate QR for: ${this.phoneNumber}`);
        return;
      }
      this.lastQRTime = now;
      this.qrCode = qr;
      this.isQRScanned = false;
      this.isConnecting = true;
      console.log(`📱 QR Code generated for: ${this.phoneNumber}`);
      qrcode.generate(qr, { small: true });
      await updateStatusInLaravel("Qr generated", 0, this.phoneNumber, this.appDomainName);
      return;
    }

    if (connection === "open") {
      console.log(`✅ WhatsApp Connected for: ${this.phoneNumber}`);
      console.log(`📞 User ID: ${this.sock.user?.id}`);
      
      this.isConnecting = false;
      this.isConnected = true;
      this.appLocals.client_ready[this.phoneNumber] = true;
      this.connectionRetries = 0;
      this.qrCode = null;
      this.isQRScanned = true;
      this.isReconnecting = false;
      
      await new Promise(resolve => setTimeout(resolve, 2000));
      
      try {
        await saveCreds();
        console.log(`💾 Session saved after connection for: ${this.phoneNumber}`);
      } catch (error) {
        console.error("❌ Error saving session:", error);
      }
      
      await updateStatusInLaravel("Ready", 100, this.phoneNumber, this.appDomainName);
    }

    if (connection === "close") {
      this.isConnecting = false;
      this.isConnected = false;
      
      if (this.isReconnecting) {
        console.log(`⏳ Already reconnecting for: ${this.phoneNumber}`);
        return;
      }

      const statusCode = lastDisconnect?.error instanceof Boom
        ? lastDisconnect.error.output?.statusCode
        : null;
      const errorMessage = lastDisconnect?.error?.message || "Unknown";
      
      console.log(`❌ Connection closed for: ${this.phoneNumber}`);
      console.log(`📊 Status code: ${statusCode}`);
      console.log(`📄 Error: ${errorMessage}`);
      
      this.appLocals.client_ready[this.phoneNumber] = false;
      delete this.appLocals.clients[this.phoneNumber];

      if (statusCode === 401) {
        console.log(`🔒 401 Conflict detected - Session conflict or duplicate connection`);
        this.shouldStayConnected = false;
        
        try {
          if (this.sock) {
            await this.sock.logout();
          }
        } catch (e) {
          console.error("❌ Logout error:", e.message);
        }
        
        await this.deleteSessionFiles();
        await updateStatusInLaravel("Logged Out", 0, this.phoneNumber, this.appDomainName);
        console.log(`⛔ Stopped reconnection due to 401 conflict`);
        return;
      }

      const isLoggedOut = 
        statusCode === DisconnectReason.loggedOut ||
        statusCode === 403;

      const isBadSession = statusCode === DisconnectReason.badSession;

      const shouldStopReconnecting = 
        !this.shouldStayConnected ||
        this.connectionRetries >= this.maxRetries ||
        isLoggedOut;

      if (shouldStopReconnecting) {
        console.log(`⛔ Stopping reconnection for: ${this.phoneNumber}`);
        
        let logoutStatus = "Connection Failed";
        
        if (isLoggedOut) {
          logoutStatus = "Logged Out";
          console.log(`🔒 User logged out - cleaning session`);
          try {
            if (this.sock) {
              await this.sock.logout();
            }
          } catch (logoutError) {
            console.error("❌ Error during explicit logout:", logoutError);
          }
          await this.deleteSessionFiles();
        }
        
        await updateStatusInLaravel(logoutStatus, 0, this.phoneNumber, this.appDomainName);
        return;
      }

      if (isBadSession) {
        console.log(`🔧 Bad session detected, cleaning and restarting...`);
        await this.deleteSessionFiles();
      }

      let delay = 3000;
      
      if (statusCode === 500) {
        console.log("⚠️ Stream error (500) - attempting reconnection");
        delay = 5000;
      } else if (statusCode === 515) {
        console.log("⚠️ Stream Error (515) - QR scanned, reconnecting with session");
        this.nextConnectStatus = { status: "Syncing data", percent: 75 };
        delay = 2000;
        
        try {
          await saveCreds();
          console.log("💾 Credentials saved before reconnect");
        } catch (e) {
          console.error("❌ Error saving creds:", e);
        }
      } else if (statusCode === 408 || statusCode === 440) {
        delay = 7000;
      } else if (statusCode === 428) {
        delay = 4000;
      } else {
        this.nextConnectStatus = { status: "Reconnecting", percent: 50 };
      }

      this.connectionRetries++;
      this.isReconnecting = true;

      console.log(
        `🔄 Reconnection attempt ${this.connectionRetries}/${this.maxRetries} for: ${this.phoneNumber} (delay: ${delay}ms)`
      );

      setTimeout(async () => {
        try {
          console.log(`♻️ Reconnecting: ${this.phoneNumber}`);
          await this.start();
        } catch (error) {
          console.error(
            `❌ Reconnection failed for ${this.phoneNumber}:`,
            error
          );
          this.isReconnecting = false;
          
          if (this.connectionRetries >= this.maxRetries) {
            await updateStatusInLaravel("Connection Failed", 0, this.phoneNumber, this.appDomainName);
          }
        }
      }, delay);
    }

    if (connection === "connecting") {
      this.isConnecting = true;
      const statusToSend = this.nextConnectStatus || { status: "Connecting", percent: 25 };
      await updateStatusInLaravel(statusToSend.status, statusToSend.percent, this.phoneNumber, this.appDomainName);
      
      if (this.nextConnectStatus) {
        this.nextConnectStatus = null;
      }
      
      console.log(`🔄 ${statusToSend.status} for: ${this.phoneNumber}`);
    }
  }

  async handleConnectionUpdateWithPairing(update, saveCreds) {
    const { connection, lastDisconnect, qr, isNewLogin } = update;

    /**
     * STEP 1 — REAL WHATSAPP PAIRING CODE
     * -----------------------------------
     * This is emitted from socket.js "pair-device" stanza.
     * NOT from requestPairingCode() — WhatsApp sends the real code here.
     */
    if (update.pairingCode) {
        this.pairingCode = update.pairingCode;
        console.log(`📌 REAL PAIRING CODE RECEIVED for ${this.phoneNumber}: ${this.pairingCode}`);

        await updateStatusInLaravel(
            "Code generated",
            0,
            this.phoneNumber,
            this.appDomainName
        );

        return; // STOP — pairing code fully handled
    }


    /**
     * STEP 2 — CONNECTION OPEN (successful)
     */
    if (connection === "open") {
        console.log(`✅ WhatsApp Connected for: ${this.phoneNumber}`);
        console.log(`📞 User ID: ${this.sock.user?.id}`);

        this.isConnecting = false;
        this.isConnected = true;
        this.appLocals.client_ready[this.phoneNumber] = true;
        this.connectionRetries = 0;
        this.qrCode = null;
        this.pairingCode = null;
        this.isQRScanned = true;
        this.isReconnecting = false;

        await new Promise(resolve => setTimeout(resolve, 2000));

        try {
            await saveCreds();
            console.log(`💾 Session saved after connection for: ${this.phoneNumber}`);
        } catch (error) {
            console.error("❌ Error saving session:", error);
        }

        await updateStatusInLaravel("Ready", 100, this.phoneNumber, this.appDomainName);
        return;
    }


    /**
     * STEP 3 — CONNECTION CLOSED
     */
    if (connection === "close") {
        this.isConnecting = false;
        this.isConnected = false;
        this.pairingCode = null;

        if (this.isReconnecting) {
            console.log(`⏳ Already reconnecting for: ${this.phoneNumber}`);
            return;
        }

        const statusCode = lastDisconnect?.error instanceof Boom
            ? lastDisconnect.error.output?.statusCode
            : null;

        const errorMessage = lastDisconnect?.error?.message || "Unknown";

        console.log(`❌ Connection closed for: ${this.phoneNumber}`);
        console.log(`📊 Status code: ${statusCode}`);
        console.log(`📄 Error: ${errorMessage}`);

        this.appLocals.client_ready[this.phoneNumber] = false;
        delete this.appLocals.clients[this.phoneNumber];

        // 401 — Session Conflict
        if (statusCode === 401) {
            console.log(`🔒 401 Conflict detected - Session conflict or duplicate connection`);
            this.shouldStayConnected = false;

            try {
                if (this.sock) {
                    await this.sock.logout();
                }
            } catch (e) {
                console.error("❌ Logout error:", e.message);
            }

            await this.deleteSessionFiles();
            await updateStatusInLaravel("Logged Out", 0, this.phoneNumber, this.appDomainName);
            console.log(`⛔ Stopped reconnection due to 401 conflict`);
            return;
        }

        const isLoggedOut =
            statusCode === DisconnectReason.loggedOut ||
            statusCode === 403;

        const isBadSession = statusCode === DisconnectReason.badSession;

        const shouldStopReconnecting =
            !this.shouldStayConnected ||
            this.connectionRetries >= this.maxRetries ||
            isLoggedOut;

        if (shouldStopReconnecting) {
            console.log(`⛔ Stopping reconnection for: ${this.phoneNumber}`);

            let logoutStatus = "Connection Failed";

            if (isLoggedOut) {
                logoutStatus = "Logged Out";
                console.log(`🔒 User logged out - cleaning session`);
                try {
                    if (this.sock) {
                        await this.sock.logout();
                    }
                } catch (logoutError) {
                    console.error("❌ Error during explicit logout:", logoutError);
                }
                await this.deleteSessionFiles();
            }

            await updateStatusInLaravel(logoutStatus, 0, this.phoneNumber, this.appDomainName);
            return;
        }

        if (isBadSession) {
            console.log(`🔧 Bad session detected, cleaning and restarting...`);
            await this.deleteSessionFiles();
        }

        // reconnection delay logic
        let delay = 3000;

        if (statusCode === 500) {
            console.log("⚠️ Stream error (500) - attempting reconnection");
            delay = 5000;
        } else if (statusCode === 515) {
            console.log("⚠️ Stream Error (515) - Code entered, reconnecting with session");
            this.nextConnectStatus = { status: "Syncing data", percent: 75 };
            delay = 2000;

            try {
                await saveCreds();
                console.log("💾 Credentials saved before reconnect");
            } catch (e) {
                console.error("❌ Error saving creds:", e);
            }
        } else if (statusCode === 408 || statusCode === 440) {
            delay = 7000;
        } else if (statusCode === 428) {
            delay = 4000;
        } else {
            this.nextConnectStatus = { status: "Reconnecting", percent: 50 };
        }

        this.connectionRetries++;
        this.isReconnecting = true;

        console.log(
            `🔄 Reconnection attempt ${this.connectionRetries}/${this.maxRetries} for: ${this.phoneNumber} (delay: ${delay}ms)`
        );

        setTimeout(async () => {
            try {
                console.log(`♻️ Reconnecting: ${this.phoneNumber}`);
                await this.start();
            } catch (error) {
                console.error(
                    `❌ Reconnection failed for ${this.phoneNumber}:`,
                    error
                );
                this.isReconnecting = false;

                if (this.connectionRetries >= this.maxRetries) {
                    await updateStatusInLaravel("Connection Failed", 0, this.phoneNumber, this.appDomainName);
                }
            }
        }, delay);

        return;
    }


    /**
     * STEP 4 — CONNECTION IN PROGRESS
     */
    if (connection === "connecting") {
        this.isConnecting = true;

        const statusToSend = this.nextConnectStatus || {
            status: "Connecting",
            percent: 25
        };

        await updateStatusInLaravel(
            statusToSend.status,
            statusToSend.percent,
            this.phoneNumber,
            this.appDomainName
        );

        if (this.nextConnectStatus) {
            this.nextConnectStatus = null;
        }

        console.log(`🔄 ${statusToSend.status} for: ${this.phoneNumber}`);
    }
}


  async handleMessages(messageUpdate) {
    const { messages, type } = messageUpdate;
    if (type !== "notify") return;

    for (const message of messages) {
      try {
        if (message.key.fromMe) continue;

        const userNumber = message.key.remoteJid.replace("@s.whatsapp.net", "");
        const messageText =
          message.message?.conversation ||
          message.message?.extendedTextMessage?.text ||
          message.message?.buttonsResponseMessage?.selectedButtonId ||
          message.message?.listResponseMessage?.singleSelectReply?.selectedRowId ||
          "";

        console.log(`📨 Message from ${userNumber}: ${messageText}`);

        const sessionKey = `${this.phoneNumber}_${userNumber}`;
        
        const activeSession = this.appLocals.activeFlowSessions[sessionKey];
        
        if (activeSession && activeSession.status === "active" && activeSession.waitingForInput) {
          console.log(`🔄 User in active flow - handling flow response`);
          
          if (activeSession.timeoutTimer) {
            clearTimeout(activeSession.timeoutTimer);
          }
          
          await handleFlowResponse(
            message,
            activeSession,
            userNumber,
            this.phoneNumber,
            this.sock,
            this.appLocals
          );
          
          this.setFlowTimeout(sessionKey, activeSession);
          continue;
        }

        if (this.isInCooldown(sessionKey)) {
          console.log(`⏳ User in cooldown period - ignoring message`);
          continue;
        }

        const keywordData = await this.checkKeywordReply(userNumber, messageText);
        
        if (!keywordData) {
          console.log("⚠️ No keyword match found");
          continue;
        }

        const { reply_type, flow_id, reply, timeout, cooldown } = keywordData;

        if (reply_type === 'flow' && flow_id) {
          await this.handleFlowAutoReply(
            message, 
            userNumber, 
            flow_id, 
            messageText,
            timeout || 30,
            cooldown || 300
          );
        } else if (reply_type === 'custom') {
          await this.handleCustomAutoReply(message, reply);
        }

      } catch (error) {
        console.error("❌ Error handling message:", error);
      }
    }
  }

  isInCooldown(sessionKey) {
    const cooldownData = this.appLocals.userCooldowns[sessionKey];
    if (!cooldownData) return false;
    
    const now = Date.now();
    const cooldownEnd = cooldownData.endTime;
    
    if (now < cooldownEnd) {
      const remainingSeconds = Math.ceil((cooldownEnd - now) / 1000);
      console.log(`⏳ Cooldown active: ${remainingSeconds}s remaining`);
      return true;
    }
    
    delete this.appLocals.userCooldowns[sessionKey];
    return false;
  }

  setCooldown(sessionKey, cooldownSeconds) {
    const endTime = Date.now() + (cooldownSeconds * 1000);
    this.appLocals.userCooldowns[sessionKey] = {
      endTime,
      cooldownSeconds
    };
    console.log(`⏰ Cooldown set: ${cooldownSeconds}s for ${sessionKey}`);
  }

  setFlowTimeout(sessionKey, session) {
    const timeoutSeconds = session.timeoutSeconds || 30;
    
    if (session.timeoutTimer) {
      clearTimeout(session.timeoutTimer);
    }
    
    session.timeoutTimer = setTimeout(() => {
      console.log(`⏱️ Flow timeout for ${sessionKey} - ending session`);
      
      session.status = "timeout";
      session.waitingForInput = null;
      
      if (session.cooldownSeconds) {
        this.setCooldown(sessionKey, session.cooldownSeconds);
      }
      
      delete this.appLocals.activeFlowSessions[sessionKey];
      
    }, timeoutSeconds * 1000);
    
    console.log(`⏰ Flow timeout set: ${timeoutSeconds}s for ${sessionKey}`);
  }

  async checkKeywordReply(userNumber, messageText) {
    try {
      const keyword = messageText.toLowerCase().trim();
      if (!keyword) return null;

      const from = `${userNumber}@s.whatsapp.net`;
      const apiUrl = `${this.appDomainName}/api/keyword-replies?keyword=${encodeURIComponent(keyword)}&mobile=${from}&phone=${this.phoneNumber}`;
      
      console.log(`🌐 Checking keyword: ${apiUrl}`);
      
      const response = await axios.get(apiUrl);
      console.log("📦 API response:", response.data);

      if (!response.data || response.data.length === 0) {
        return null;
      }

      const data = response.data[0];
      
      if (data.reply === 'notallow' || data.reply === 'default') {
        console.log("⚠️ Keyword blocked or default");
        return null;
      }

      return {
        reply_type: data.reply_type || 'custom',
        flow_id: data.flow_id || null,
        reply: data.reply || null,
        timeout: data.timeout || 30,
        cooldown: data.cooldown || 300
      };

    } catch (error) {
      console.error("❌ Error checking keyword:", error.message);
      return null;
    }
  }

  async handleFlowAutoReply(message, userNumber, flowId, messageText, timeoutSeconds, cooldownSeconds) {
    try {
      console.log(`🔄 Starting flow ${flowId} for ${userNumber}`);
      console.log(`⏰ Timeout: ${timeoutSeconds}s, Cooldown: ${cooldownSeconds}s`);

      const sessionKey = `${this.phoneNumber}_${userNumber}`;
      
      if (this.appLocals.activeFlowSessions[sessionKey]) {
        console.log(`⚠️ Active session exists, clearing old session`);
        const oldSession = this.appLocals.activeFlowSessions[sessionKey];
        if (oldSession.timeoutTimer) {
          clearTimeout(oldSession.timeoutTimer);
        }
      }

      const flowResponse = await axios.get(
        `${this.appDomainName}/api/flows/${flowId}`
      );

      if (!flowResponse.data.success) {
        console.error(`❌ Flow ${flowId} not found`);
        return;
      }

      const flowData = flowResponse.data.data.flow_data;

      this.appLocals.activeFlowSessions[sessionKey] = {
        sessionId: `${sessionKey}_${Date.now()}`,
        flowId: flowId,
        flowData: flowData,
        currentNodeId: null,
        userVariables: { user_message: messageText },
        messageHistory: [
          {
            type: "received",
            message: messageText,
            timestamp: moment().format(),
          },
        ],
        status: "active",
        startedAt: moment().format(),
        phoneNumber: this.phoneNumber,
        timeoutSeconds: timeoutSeconds,
        cooldownSeconds: cooldownSeconds,
        timeoutTimer: null
      };

      const activeSession = this.appLocals.activeFlowSessions[sessionKey];

      console.log(`✅ Session created: ${sessionKey}`);

      const startNode = flowData.flowNodes[0];
      await executeFlowNode(
        startNode,
        userNumber,
        this.phoneNumber,
        this.sock,
        this.appLocals,
        sessionKey
      );

      this.setFlowTimeout(sessionKey, activeSession);

      console.log(`✅ Flow ${flowId} started for ${userNumber}`);

    } catch (error) {
      console.error("❌ Error in flow auto-reply:", error);
    }
  }

  async handleCustomAutoReply(message, replyData) {
    try {
      const from = message.key.remoteJid;
      console.log(`💬 Sending custom reply to ${from}`);

      let parsedReply;
      try {
        parsedReply = JSON.parse(replyData);
      } catch {
        parsedReply = null;
      }

      if (parsedReply && parsedReply.type) {
        await this.sendMediaReply(from, parsedReply);
      } else {
        await this.sock.sendMessage(from, { text: replyData });
        console.log("✅ Text reply sent");
      }

    } catch (error) {
      console.error("❌ Error in custom auto-reply:", error);
    }
  }

  async sendMediaReply(to, replyData) {
    try {
      const { type, url, filename, mimetype, caption } = replyData;

      console.log(`📤 Sending ${type} to ${to}`);

      const response = await axios.get(url, { responseType: 'arraybuffer' });
      const buffer = Buffer.from(response.data);

      switch (type) {
        case 'document':
          await this.sock.sendMessage(to, {
            document: buffer,
            fileName: filename || 'document.pdf',
            mimetype: mimetype || 'application/pdf'
          });
          break;

        case 'image':
          await this.sock.sendMessage(to, {
            image: buffer,
            caption: caption || ''
          });
          break;

        case 'video':
          await this.sock.sendMessage(to, {
            video: buffer,
            caption: caption || ''
          });
          break;

        default:
          console.warn(`⚠️ Unsupported media type: ${type}`);
      }

      console.log(`✅ ${type} sent successfully`);

    } catch (error) {
      console.error(`❌ Error sending media:`, error.message);
    }
  }

  async updateStatus(status, percent, qrCode = null) {
    try {
      await updateStatusInLaravel(status, percent, this.phoneNumber, this.appDomainName, qrCode);
    } catch (error) {
      console.error("Error updating status:", error);
    }
  }

  async deleteSessionFiles() {
    try {
      await fs.remove(this.authFolder);
      console.log(`🗂️ Session files deleted for ${this.phoneNumber}`);
    } catch (err) {
      console.error(
        `❌ Error deleting session files for ${this.phoneNumber}:`,
        err
      );
    }
  }

  async logout() {
    try {
      this.shouldStayConnected = false;
      this.isConnecting = false;
      this.isConnected = false;
      
      if (this.sock) {
        try {
          await this.sock.logout();
        } catch (error) {
          console.error("Error during socket logout:", error);
        }
        
        delete this.appLocals.clients[this.phoneNumber];
        delete this.appLocals.client_ready[this.phoneNumber];
      }
      
      const sessionKeys = Object.keys(this.appLocals.activeFlowSessions);
      sessionKeys.forEach(key => {
        if (key.startsWith(`${this.phoneNumber}_`)) {
          const session = this.appLocals.activeFlowSessions[key];
          if (session && session.timeoutTimer) {
            clearTimeout(session.timeoutTimer);
          }
          delete this.appLocals.activeFlowSessions[key];
          delete this.appLocals.userCooldowns[key];
        }
      });
      
      await this.deleteSessionFiles();
      console.log(`👋 Logged out successfully: ${this.phoneNumber}`);
    } catch (error) {
      console.error("Error during logout:", error);
    }
  }

  getQRCode() {
    return this.qrCode;
  }

  async handleMessageStatusUpdate(updates) {
    for (const update of updates) {
      try {
        const messageId = update.key.id;
        const remoteJid = update.key.remoteJid;
        const status = update.update.status;
        
        if (!status || !messageId) continue;
        
        let statusName = null;
        let timestampField = null;
        
        if (status === 2) {
          statusName = "delivered";
          timestampField = "delivered_at";
        } else if (status === 3) {
          statusName = "read";
          timestampField = "read_at";
        }
        
        if (statusName) {
          console.log(`📨 Message ${messageId} → ${statusName.toUpperCase()}`);
          
          const relatedBroadcast = this.appLocals.scheduledMessages.find(b => 
            b.sentMessages && b.sentMessages[messageId]
          );
          
          if (relatedBroadcast) {
            const contactInfo = relatedBroadcast.sentMessages[messageId];
            
            console.log(`🔗 Found in broadcast: ${relatedBroadcast.broadcastId}, Contact: ${contactInfo.contactId}`);
            
            try {
              await axios.post(`${this.appDomainName}/api/update-message-status`, {
                broadcast_id: relatedBroadcast.broadcastId,
                contact_id: contactInfo.contactId,
                status: statusName,
                whatsapp_message_id: messageId,
                [timestampField]: new Date().toISOString()
              });
              
              console.log(`✅ Status updated in database: ${statusName}`);
            } catch (error) {
              console.error(`❌ Failed to update status in database:`, error.message);
            }
          } else {
            console.log(`⚠️ Message ${messageId} not found in tracked broadcasts`);
          }
        }
      } catch (error) {
        console.error("❌ Error handling message status update:", error);
      }
    }
  }
}