// Booking flow smoke — drives the multi-service Book Appointment node end to
// end with a mock Laravel + mock Baileys sock, asserting EVERY step actually
// sends a message: day list → time list → question 1 → question 2 →
// confirmation → copy-to-number alert. This is the path that broke in
// production (nothing after the slot pick), so we verify each bubble leaves.
//
//   node node/test/booking-smoke.mjs

import { executeFlowNode, handleFlowResponse } from '../services/flowService.js';
import { createServer } from 'node:http';

// ── Mock Laravel: the four booking endpoints the runtime calls. ──
const server = createServer((req, res) => {
  let body = '';
  req.on('data', c => (body += c));
  req.on('end', () => {
    const url = req.url.split('?')[0];
    const q = req.url.includes('?') ? req.url.split('?')[1] : '';
    let out = { ok: false, error: 'not_mocked', url };

    if (url === '/api/booking/types') {
      out = { ok: true, available: true, types: [{
        id: 23, name: 'Doctor Consultation', duration: 20,
        intro_message: 'A couple of quick details before we confirm your visit.',
        due_now: 0, currency: 'USD', has_gateway: false,
        questions: [
          { label: 'Patient full name', type: 'text', required: true, options: [], map: 'name' },
          { label: 'Reason for visit',  type: 'text', required: true, options: [], map: null },
        ],
      }] };
    } else if (url === '/api/booking/slots') {
      if (/(^|&)day=/.test(q)) {
        out = { ok: true, times: [
          { start: '2026-08-20T10:40:00+05:30', end: '2026-08-20T11:00:00+05:30', label: '10:40 AM' },
          { start: '2026-08-20T11:00:00+05:30', end: '2026-08-20T11:20:00+05:30', label: '11:00 AM' },
        ], has_more: false, next_offset: 0 };
      } else {
        out = { ok: true, tz: 'Asia/Kolkata', next_offset: 0, has_more: false, days: [
          { day: '2026-08-20', label: 'Thu, 20 Aug' },
          { day: '2026-08-21', label: 'Fri, 21 Aug' },
        ] };
      }
    } else if (url === '/api/booking/reserve') {
      out = { ok: true, reservation_id: 99, expires_at: '2026-08-20T10:35:00Z' };
    } else if (url === '/api/booking/book') {
      out = { ok: true, appointment_id: 4242, status: 'confirmed', manage_token: 'TESTTOKEN123' };
    } else if (url === '/api/inbound-message') {
      try { mirrored.push(JSON.parse(body || '{}')); } catch (_) {}
      out = { ok: true };
    }
    res.writeHead(out.ok ? 200 : 404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(out));
  });
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
const MOCK_URL = `http://127.0.0.1:${server.address().port}`;

// ── Mock Baileys sock (records every outbound). ──
const sent = [];
const mirrored = []; // captures POST /api/inbound-message (team-inbox mirror)
const sock = {
  user: { id: '919783969401:1@s.whatsapp.net', name: 'Clinic' },
  ws: { readyState: 1 },
  sendMessage: async (jid, payload) => {
    let text = '';
    if (payload?.text) text = payload.text;
    else if (payload?.caption) text = payload.caption;
    const kind = payload?.interactiveButtons ? 'list'
      : payload?.sections ? 'list'
      : payload?.text ? 'text' : Object.keys(payload || {})[0] || 'unknown';
    sent.push({ jid, kind, text });
    return { key: { id: 'T' + sent.length } };
  },
};

const appLocals = { activeFlowSessions: {}, clients: {}, appDomainName: MOCK_URL };
const CUST = '919812345678', DEVICE = '919783969401';
// Production convention (BaileysClientManager): sessionKey = `${device}_${customer}`,
// executeFlowNode(node, userNumber=customer, this.phoneNumber=device, …),
// handleFlowResponse(msg, session, userNumber=customer, phoneNumber=device, …).
const sessionKey = `${DEVICE}_${CUST}`;

const flowData = {
  workspace_id: 5,
  flowNodes: [
    { id: 'book', type: 'book_appointment', flowNodeType: 'BookAppointment',
      bookingTypeId: 23, slotCount: 5, prompt: 'Please pick a time for your visit:',
      confirmation: 'Your Doctor Consultation is confirmed for {{slot}} at City Care Clinic, Room 4. See you soon!' },
    { id: 'notify', type: 'notify_number', flowNodeType: 'NotifyNumber',
      copyNumber: '910000000009', source: 'custom', kind: 'text',
      text: 'New appointment booked.\nPatient: {{name}}\nReason: {{reason_for_visit}}\nWhen: {{slot}}',
      mediaKind: '', mediaUrl: '', mediaCaption: '', mediaFilename: '' },
  ],
  flowEdges: [
    { id: 'e', sourceNodeId: 'book_1', targetNodeId: 'notify_1', source: 'book', target: 'notify', sourceHandle: 'out' },
  ],
};

appLocals.activeFlowSessions[sessionKey] = {
  sessionId: 'bk_test', flowId: 150, flowData,
  currentNodeId: null, userVariables: {}, messageHistory: [],
  status: 'active', startedAt: new Date().toISOString(),
  conversationId: 1,
};
const session = () => appLocals.activeFlowSessions[sessionKey];

// Reply builders.
const tapInteractive = (id) => ({ message: { interactiveResponseMessage: { nativeFlowResponseMessage: { paramsJson: JSON.stringify({ id }) } } } });
const typeText = (t) => ({ message: { conversation: t } });

let pass = 0, fail = 0;
const check = (cond, label, extra = '') => { if (cond) { pass++; console.log(`  PASS  ${label}`); } else { fail++; console.log(`  FAIL  ${label}  ${extra}`); } };
const lastText = () => (sent.length ? sent[sent.length - 1].text : '');
const anyTextIncludes = (from, s) => sent.slice(from).some(m => (m.text || '').includes(s));

console.log('### Booking flow — full conversation ###\n');

// 1) Node fires → day list.
await executeFlowNode(flowData.flowNodes[0], CUST, DEVICE, sock, appLocals, sessionKey);
check(sent.some(m => m.text === 'Pick a day:'), '1. day list sent ("Pick a day:")', JSON.stringify(sent.map(s => s.text)));
check(session().waitingForInput?.bookingStage === 'CHOOSE_DAY', '   parked at CHOOSE_DAY');

// 2) Pick a day → time list.
let before = sent.length;
await handleFlowResponse(tapInteractive('bk_day_0'), session(), CUST, DEVICE, sock, appLocals);
check(anyTextIncludes(before, 'Pick a time:'), '2. time list sent ("Pick a time:")', JSON.stringify(sent.slice(before).map(s => s.text)));
check(session().waitingForInput?.bookingStage === 'CHOOSE_SLOT', '   parked at CHOOSE_SLOT');

// 3) Pick a time → FIRST question (this is the step that broke).
before = sent.length;
await handleFlowResponse(tapInteractive('bk_slot_0'), session(), CUST, DEVICE, sock, appLocals);
check(anyTextIncludes(before, 'Patient full name'), '3. asked question 1 ("Patient full name")', JSON.stringify(sent.slice(before).map(s => s.text)));
check(session().waitingForInput?.bookingStage === 'ASK_QUESTIONS', '   parked at ASK_QUESTIONS');

// 4) Answer name → SECOND question.
before = sent.length;
await handleFlowResponse(typeText('Ramesh Kumar'), session(), CUST, DEVICE, sock, appLocals);
check(anyTextIncludes(before, 'Reason for visit'), '4. asked question 2 ("Reason for visit")', JSON.stringify(sent.slice(before).map(s => s.text)));

// 5) Answer reason → confirmation + notify.
before = sent.length;
await handleFlowResponse(typeText('Fever and headache'), session(), CUST, DEVICE, sock, appLocals);
check(anyTextIncludes(before, 'confirmed for'), '5. confirmation sent to patient', JSON.stringify(sent.slice(before).map(s => s.text)));

// 6) Copy-to-number alert with the personalized details.
const notif = sent.slice(before).find(m => (m.text || '').includes('New appointment booked'));
check(!!notif, '6. copy-to-number alert sent to owner');
if (notif) {
  check(notif.jid.replace(/\D+/g, '').startsWith('910000000009'), '   → alert addressed to the owner number', notif.jid);
  check(notif.text.includes('Ramesh Kumar'), '   → alert includes patient name {{name}}', notif.text);
  check(notif.text.includes('Fever and headache'), '   → alert includes {{reason_for_visit}}', notif.text);
  check(notif.text.includes('10:40'), '   → alert includes {{slot}}', notif.text);
}

// 7) Confirmation carried the interpolated slot label.
const conf = sent.find(m => (m.text || '').includes('confirmed for'));
check(conf && conf.text.includes('10:40'), '7. confirmation interpolated {{slot}} = 10:40 AM', conf?.text);

// 8) REGRESSION: every patient-facing message went to the CUSTOMER, never the
// device's own number (the bug: questions/confirmation were sent to the device).
const patientSends = sent.filter(m => !(m.text || '').includes('New appointment booked'));
const toDevice = patientSends.filter(m => m.jid.replace(/\D+/g, '').startsWith(DEVICE));
check(toDevice.length === 0, '8. no patient message leaked to the device number', JSON.stringify(toDevice.map(m => m.text?.slice(0, 30))));
check(patientSends.every(m => m.jid.replace(/\D+/g, '').startsWith(CUST)), '   every patient message addressed to the customer');

// 9) INBOX MIRROR: the day + time pickers must mirror WITH their tappable
// options (buttons), so the team inbox shows the WhatsApp-style card, not a
// bare "Pick a day:".
const mDay  = mirrored.find(m => (m.body || '') === 'Pick a day:');
const mTime = mirrored.find(m => (m.body || '') === 'Pick a time:');
check(!!mDay && Array.isArray(mDay.buttons) && mDay.buttons.length >= 2, '9. day picker mirrored to inbox WITH options', JSON.stringify(mDay?.buttons));
check(!!mTime && Array.isArray(mTime.buttons) && mTime.buttons.length >= 2, '   time picker mirrored to inbox WITH options', JSON.stringify(mTime?.buttons));
check(!!mDay && mDay.buttons.some(b => (b.text || '').includes('Thu, 20 Aug')), '   day options include real day labels', JSON.stringify(mDay?.buttons?.map(b => b.text)));
check(!!mTime && mTime.buttons.some(b => (b.text || '').includes('10:40')), '   time options include real time labels', JSON.stringify(mTime?.buttons?.map(b => b.text)));

console.log('\n--- ALL SENDS ---');
sent.forEach((m, i) => console.log(`${i}. [${m.kind}] to=${m.jid} :: ${JSON.stringify((m.text||'').slice(0,70))}`));
console.log('--- MIRRORED (inbox) ---');
mirrored.forEach((m, i) => console.log(`${i}. body=${JSON.stringify((m.body||'').slice(0,40))} buttons=${JSON.stringify((m.buttons||[]).map(b=>b.text))}`));
console.log('--- userVariables ---', JSON.stringify(session().userVariables));

console.log(`\n======  RESULT: ${pass} passed, ${fail} failed  ======`);
server.close();
process.exit(fail ? 1 : 0);
