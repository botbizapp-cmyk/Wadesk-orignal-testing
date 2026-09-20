(function () {
    var base = (typeof window.DOC_BASE !== 'undefined') ? window.DOC_BASE : './';

    function makeLink(path, text) {
        return '<li class="nav-item"><a href="' + base + path + '" class="nav-link"><span>' + text + '</span></a></li>';
    }

    function makeGroup(title) {
        return '<li class="nav-item"><div class="nav-group-title">' + title + '</div></li>';
    }

    var html = '';
    html += makeLink('index.html', 'Documentation');
    html += makeLink('pages/introduction.html', 'Introduction');
    html += makeLink('pages/key-features.html', 'Key Features');

    html += makeGroup('Installation');
    html += makeLink('pages/installation/server-requirements.html', 'Server Requirements');
    html += makeLink('pages/installation/web-installer.html', 'Web Installer Wizard');
    html += makeLink('pages/installation/post-install.html', 'Post-Install Setup');
    html += makeLink('pages/installation/troubleshooting.html', 'Troubleshooting');

    html += makeGroup('Getting Started');
    html += makeLink('pages/getting-started/workspaces.html', 'Workspaces &amp; Switching');
    html += makeLink('pages/getting-started/connect-channel.html', 'Connecting a Channel');
    html += makeLink('pages/getting-started/channel-settings.html', 'Channel Settings (Admin)');
    html += makeLink('pages/getting-started/coexistence.html', 'WhatsApp Coexistence');
    html += makeLink('pages/getting-started/multi-engine.html', 'Multiple Engines at Once');
    html += makeLink('pages/getting-started/proxy-isolation.html', 'Per-Number Proxy (Multi-IP)');
    html += makeLink('pages/getting-started/dashboard.html', 'Dashboard Overview');
    html += makeLink('pages/getting-started/plans-trial.html', 'Plans &amp; Free Trial');

    html += makeGroup('Channels');
    html += makeLink('pages/channels/whatsapp.html', 'WhatsApp');
    html += makeLink('pages/channels/instagram.html', 'Instagram');
    html += makeLink('pages/channels/facebook.html', 'Facebook Messenger');
    html += makeLink('pages/channels/telegram.html', 'Telegram');
    html += makeLink('pages/channels/tiktok.html', 'TikTok');
    html += makeLink('pages/channels/line.html', 'LINE');
    html += makeLink('pages/channels/wechat.html', 'WeChat');
    html += makeLink('pages/channels/viber.html', 'Viber');
    html += makeLink('pages/channels/sms.html', 'SMS (Twilio &amp; MSG91)');
    html += makeLink('pages/channels/email.html', 'Email (MailTrixy)');

    html += makeGroup('Team Inbox &amp; Messaging');
    html += makeLink('pages/messaging/chat.html', 'Chat (1-on-1)');
    html += makeLink('pages/messaging/team-inbox.html', 'Team Inbox');
    html += makeLink('pages/messaging/team-chat.html', 'Team Chat');
    html += makeLink('pages/messaging/campaigns.html', 'Campaigns');
    html += makeLink('pages/messaging/broadcasts.html', 'Broadcasts');
    html += makeLink('pages/messaging/scheduled.html', 'Scheduled Messages');
    html += makeLink('pages/messaging/auto-reply.html', 'Auto Reply');
    html += makeLink('pages/messaging/warmer.html', 'WhatsApp Warmer');
    html += makeLink('pages/messaging/message-history.html', 'Message History');

    html += makeGroup('Templates &amp; Automation');
    html += makeLink('pages/automation/templates.html', 'WhatsApp Templates');
    html += makeLink('pages/automation/flows.html', 'Flows (Visual Builder)');
    html += makeLink('pages/automation/wa-forms.html', 'WhatsApp Forms');
    html += makeLink('pages/automation/chatbot-widget.html', 'Chatbot Widget');
    html += makeLink('pages/automation/wa-links.html', 'Link Generator');

    html += makeGroup('AI Suite');
    html += makeLink('pages/ai/ai-crm-copilot.html', 'AI CRM Copilot');
    html += makeLink('pages/ai/ai-call-assistant.html', 'AI Call Assistant');
    html += makeLink('pages/ai/ai-training.html', 'AI Training');
    html += makeLink('pages/ai/wa-calling.html', 'WhatsApp Calling');
    html += makeLink('pages/ai/call-logs.html', 'Call Logs');

    html += makeGroup('Marketing &amp; Contacts');
    html += makeLink('pages/growth/meta-ads.html', 'Meta Ads (Click-to-WhatsApp)');
    html += makeLink('pages/growth/facebook-app-setup.html', 'Create a Facebook App (Meta Ads)');
    html += makeLink('pages/growth/contacts.html', 'Contacts &amp; Groups');
    html += makeLink('pages/growth/attributes.html', 'Attributes');
    html += makeLink('pages/growth/deals.html', 'Sales Pipeline (Deals)');
    html += makeLink('pages/growth/analytics.html', 'Analytics');

    html += makeGroup('Commerce &amp; Booking');
    html += makeLink('pages/commerce/catalog.html', 'Catalog');
    html += makeLink('pages/commerce/storefront.html', 'Storefront &amp; Orders');
    html += makeLink('pages/commerce/whatsapp-pay.html', 'WhatsApp Pay');
    html += makeLink('pages/commerce/checkout-gateways.html', 'Checkout Gateways');
    html += makeLink('pages/commerce/appointments.html', 'Appointments &amp; Booking');
    html += makeLink('pages/commerce/proposals-estimates.html', 'Proposals &amp; Estimates');
    html += makeLink('pages/commerce/projects.html', 'Projects');
    html += makeLink('pages/commerce/auto-invoicing.html', 'Auto-Invoicing');
    html += makeLink('pages/commerce/payment-links.html', 'Payment Links &amp; Checkout');

    html += makeGroup('Integrations');
    html += makeLink('pages/integrations/google.html', 'Google Workspace');
    html += makeLink('pages/integrations/ecommerce.html', 'E-commerce &amp; CRM');
    html += makeLink('pages/commerce/shopify.html', 'Shopify');
    html += makeLink('pages/integrations/woocommerce.html', 'WooCommerce Order Webhooks');
    html += makeLink('pages/integrations/webhooks.html', 'Webhooks');
    html += makeLink('pages/integrations/slack.html', 'Slack');
    html += makeLink('pages/integrations/trello.html', 'Trello');
    html += makeLink('pages/integrations/extension.html', 'Browser Extension');

    html += makeGroup('Account &amp; Team');
    html += makeLink('pages/account/account-billing.html', 'Account &amp; Billing');
    html += makeLink('pages/account/team-members.html', 'Team Members &amp; Roles');
    html += makeLink('pages/account/notifications.html', 'Notifications');
    html += makeLink('pages/account/activity-log.html', 'Activity Log');

    html += makeGroup('Admin Panel');
    html += makeLink('pages/admin-panel/dashboard.html', 'Admin Dashboard');
    html += makeLink('pages/admin-panel/user-management.html', 'User Management');
    html += makeLink('pages/admin-panel/workspaces.html', 'Workspaces');
    html += makeLink('pages/admin-panel/plans-packages.html', 'Plans &amp; Packages');
    html += makeLink('pages/admin-panel/payment-gateways.html', 'Payment Gateways');
    html += makeLink('pages/admin-panel/localization.html', 'Currencies &amp; Languages');
    html += makeLink('pages/admin-panel/ai-keys.html', 'AI &amp; API Keys');
    html += makeLink('pages/admin-panel/ai-dashboard.html', 'AI Usage Dashboard');
    html += makeLink('pages/admin-panel/system-health.html', 'System Health');
    html += makeLink('pages/admin-panel/security.html', 'Security &amp; Audit Log');
    html += makeLink('pages/admin-panel/frontend-editor.html', 'Frontend Live Editor');
    html += makeLink('pages/admin-panel/appearance.html', 'Appearance &amp; Theme Colours');
    html += makeLink('pages/admin-panel/auth-pages.html', 'Auth Page Editor');
    html += makeLink('pages/admin-panel/system-settings.html', 'System Settings');
    html += makeLink('pages/admin-panel/cloud-storage.html', 'Cloud Storage');
    html += makeLink('pages/admin-panel/blog.html', 'Blog &amp; SEO');
    html += makeLink('pages/admin-panel/announcements.html', 'Announcements');
    html += makeLink('pages/admin-panel/billing-finance.html', 'Billing &amp; Finance');
    html += makeLink('pages/admin-panel/coupons-credits.html', 'Coupons &amp; Credits');
    html += makeLink('pages/admin-panel/addons.html', 'Add-on Packages');
    html += makeLink('pages/admin-panel/extensions.html', 'Add-ons &amp; Extensions');
    html += makeLink('pages/admin-panel/instagram-engine.html', 'Instagram Engine');
    html += makeLink('pages/admin-panel/roles-permissions.html', 'Roles &amp; Permissions');
    html += makeLink('pages/admin-panel/translation.html', 'Translation');
    html += makeLink('pages/admin-panel/platform-inbox.html', 'Platform Inbox &amp; Impersonation');
    html += makeLink('pages/admin-panel/support-guidebook.html', 'Support &amp; Guidebook');

    html += makeGroup('BSP &amp; Reseller Billing');
    html += makeLink('pages/bsp-billing/overview.html', 'Overview &amp; Money Funnel');
    html += makeLink('pages/bsp-billing/meta-setup.html', 'Meta Setup (Token &amp; Credit Line)');
    html += makeLink('pages/bsp-billing/api-reference.html', 'Meta API Reference (2026)');
    html += makeLink('pages/bsp-billing/admin-pages.html', 'Admin Pages (Pricing &amp; Income)');
    html += makeLink('pages/bsp-billing/customer-flow.html', 'Customer Flow (Connect &amp; Wallet)');
    html += makeLink('pages/bsp-billing/billing-engine.html', 'Billing Engine (charge-on-delivery)');

    html += makeGroup('Developer Guide');
    html += makeLink('pages/developer-guide/architecture.html', 'Architecture Overview');
    html += makeLink('pages/developer-guide/engines.html', 'WhatsApp Engines');
    html += makeLink('pages/developer-guide/rest-api.html', 'REST API');
    html += makeLink('pages/developer-guide/api-endpoints.html', 'API Endpoints');
    html += makeLink('pages/developer-guide/database-schema.html', 'Database Schema');
    html += makeLink('pages/developer-guide/third-party-integrations.html', 'Third-Party Integrations');
    html += makeLink('pages/developer-guide/node-bridge.html', 'Node Bridge &amp; Realtime');
    html += makeLink('pages/developer-guide/customization.html', 'Customization Guide');

    html += makeGroup('Security');
    html += makeLink('pages/security/authentication.html', 'Authentication &amp; 2FA');
    html += makeLink('pages/security/plan-enforcement.html', 'Plan Enforcement');
    html += makeLink('pages/security/data-privacy.html', 'Data &amp; Privacy');

    html += makeGroup('FAQs');
    html += makeLink('pages/faqs/video-guides.html', 'Video Guide');
    html += makeLink('pages/faqs/general.html', 'General FAQs');
    html += makeLink('pages/faqs/installation.html', 'Installation FAQs');
    html += makeLink('pages/faqs/errors-solutions.html', 'Errors &amp; Solutions');
    html += makeLink('pages/faqs/whatsapp-bans.html', 'WhatsApp &amp; Ban Safety');
    html += makeLink('pages/faqs/demo-limitations.html', 'Demo Limitations');
    html += makeLink('pages/faqs/disclaimer.html', 'Disclaimer');
    html += makeLink('pages/faqs/privacy-policy.html', 'Privacy Policy');

    html += makeGroup('Update Log');
    html += makeLink('pages/update-details/changelog.html', 'Change Log');
    html += makeLink('pages/update-details/update-process.html', 'Update Process');

    html += makeLink('pages/conclusion/support.html', 'Support');
    html += makeLink('pages/conclusion/source-credits.html', 'Source &amp; Credits');
    html += makeLink('pages/conclusion/conclusion.html', 'Conclusion');

    var navEl = document.querySelector('.sidebar-nav');
    if (navEl) {
        navEl.innerHTML = '<ul class="nav-list">' + html + '</ul>';
    }

    // Highlight active link
    var currentHref = window.location.href.split('?')[0].split('#')[0];
    var links = document.querySelectorAll('.sidebar-nav .nav-link');
    links.forEach(function (link) {
        var linkHref = link.href.split('?')[0].split('#')[0];
        if (linkHref === currentHref) {
            link.classList.add('active');
        }
    });
})();
