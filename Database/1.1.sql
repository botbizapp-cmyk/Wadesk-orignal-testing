-- ============================================================================
-- WaDesk 1.0 -> 1.1 schema upgrade
--
-- Run this ONCE against a 1.0 database to bring its schema to 1.1, for
-- installs that cannot run `php artisan migrate`. Generated from the exact
-- DDL Laravel would have executed for the 29 migrations added since 1.0
-- (captured via `php artisan migrate --pretend`). Safe to run inside one
-- transaction on InnoDB; back up first.
--
-- NOTE: a few data-refresh migrations that read-then-write are appended
--       manually at the bottom (pretend cannot emit those).
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 2026_06_10_000100_add_app_api_auth_columns
-- ============================================================
alter table `users` add `passcode` varchar(255) null after `password`;
create table `user_otps` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `otp` varchar(10) not null, `expires_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `user_otps` add constraint `user_otps_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `user_otps` add unique `user_otps_user_id_unique`(`user_id`);

-- ============================================================
-- 2026_06_10_063140_create_personal_access_tokens_table
-- ============================================================
create table `personal_access_tokens` (`id` bigint unsigned not null auto_increment primary key, `tokenable_type` varchar(255) not null, `tokenable_id` bigint unsigned not null, `name` text not null, `token` varchar(64) not null, `abilities` text null, `last_used_at` timestamp null, `expires_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `personal_access_tokens` add index `personal_access_tokens_tokenable_type_tokenable_id_index`(`tokenable_type`, `tokenable_id`);
alter table `personal_access_tokens` add unique `personal_access_tokens_token_unique`(`token`);
alter table `personal_access_tokens` add index `personal_access_tokens_expires_at_index`(`expires_at`);

-- ============================================================
-- 2026_06_10_100000_create_slack_integrations_table
-- ============================================================
create table `slack_integrations` (`id` bigint unsigned not null auto_increment primary key, `workspace_id` bigint unsigned not null, `user_id` bigint unsigned not null, `team_id` varchar(64) null, `team_name` varchar(191) null, `bot_user_id` varchar(64) null, `bot_token` text not null, `signing_secret` text not null, `slash_command` varchar(32) not null default '/wa', `status` varchar(16) not null default 'active', `metadata` json null, `last_used_at` timestamp null, `connected_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `slack_integrations` add unique `slack_integrations_workspace_id_unique`(`workspace_id`);
alter table `slack_integrations` add unique `slack_integrations_team_id_unique`(`team_id`);
alter table `slack_integrations` add index `slack_integrations_user_id_index`(`user_id`);
create table `slack_integration_logs` (`id` bigint unsigned not null auto_increment primary key, `integration_id` bigint unsigned null, `workspace_id` bigint unsigned not null, `event` varchar(40) not null default 'command', `detail` varchar(500) null, `status` varchar(16) not null default 'ok', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `slack_integration_logs` add index `slack_integration_logs_integration_id_index`(`integration_id`);
alter table `slack_integration_logs` add index `slack_integration_logs_workspace_id_created_at_index`(`workspace_id`, `created_at`);

-- ============================================================
-- 2026_06_10_100100_create_trello_integrations_table
-- ============================================================
create table `trello_integrations` (`id` bigint unsigned not null auto_increment primary key, `workspace_id` bigint unsigned not null, `user_id` bigint unsigned not null, `api_key` text not null, `api_secret` text not null, `token` text not null, `board_id` varchar(64) not null, `board_name` varchar(191) null, `webhook_id` varchar(64) null, `events` json null, `notify_mode` varchar(16) not null default 'assignee', `notify_number` text null, `member_map` json null, `status` varchar(16) not null default 'active', `metadata` json null, `last_event_at` timestamp null, `connected_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `trello_integrations` add unique `trello_integrations_workspace_id_board_id_unique`(`workspace_id`, `board_id`);
alter table `trello_integrations` add index `trello_integrations_webhook_id_index`(`webhook_id`);
alter table `trello_integrations` add index `trello_integrations_user_id_index`(`user_id`);
create table `trello_integration_logs` (`id` bigint unsigned not null auto_increment primary key, `integration_id` bigint unsigned null, `workspace_id` bigint unsigned not null, `event` varchar(40) not null default 'webhook', `detail` varchar(500) null, `status` varchar(16) not null default 'ok', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `trello_integration_logs` add index `trello_integration_logs_integration_id_index`(`integration_id`);
alter table `trello_integration_logs` add index `trello_integration_logs_workspace_id_created_at_index`(`workspace_id`, `created_at`);

-- ============================================================
-- 2026_06_10_100200_add_slack_trello_plan_flags
-- ============================================================
alter table `packages` add `integration_slack` tinyint(1) not null default '0' after `integration_hubspot`;
alter table `packages` add `integration_trello` tinyint(1) not null default '0' after `integration_slack`;

-- ============================================================
-- 2026_06_11_120000_add_pinned_to_broadcasts_table
-- ============================================================
alter table `broadcasts` add `pinned` tinyint(1) not null default '0' after `status`;

-- ============================================================
-- 2026_06_12_000000_add_header_location_to_wa_templates
-- ============================================================
alter table `wa_templates` add `header_location` text null after `header`;

-- ============================================================
-- 2026_06_12_120000_add_content_to_broadcasts_table
-- ============================================================
alter table `broadcasts` add `temp_caption` text null;
alter table `broadcasts` add `template_type` varchar(40) null;
alter table `broadcasts` add `temp_image` varchar(500) null;
alter table `broadcasts` add `button_text` text null;
alter table `broadcasts` add `latitude` varchar(32) null;
alter table `broadcasts` add `longitude` varchar(32) null;

-- ============================================================
-- 2026_06_13_000100_create_api_keys_table
-- ============================================================
create table `api_keys` (`id` bigint unsigned not null auto_increment primary key, `workspace_id` bigint unsigned not null, `user_id` bigint unsigned not null, `name` varchar(255) null, `key_hash` varchar(64) not null, `prefix` varchar(16) not null, `scopes` json null, `last_used_at` timestamp null, `expires_at` timestamp null, `revoked_at` timestamp null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `api_keys` add index `api_keys_workspace_id_index`(`workspace_id`);
alter table `api_keys` add index `api_keys_user_id_index`(`user_id`);
alter table `api_keys` add unique `api_keys_key_hash_unique`(`key_hash`);
alter table `api_keys` add index `api_keys_prefix_index`(`prefix`);

-- ============================================================
-- 2026_06_13_100000_create_pipelines_table
-- ============================================================
create table `pipelines` (`id` bigint unsigned not null auto_increment primary key, `workspace_id` bigint unsigned not null, `name` varchar(120) not null, `is_default` tinyint(1) not null default '0', `currency` varchar(10) not null default 'INR', `sort_order` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `pipelines` add index `pipelines_workspace_id_is_default_index`(`workspace_id`, `is_default`);

-- ============================================================
-- 2026_06_13_100100_create_pipeline_stages_table
-- ============================================================
create table `pipeline_stages` (`id` bigint unsigned not null auto_increment primary key, `pipeline_id` bigint unsigned not null, `workspace_id` bigint unsigned not null, `name` varchar(120) not null, `sort_order` int unsigned not null default '0', `color` varchar(16) not null default '#25D366', `is_won` tinyint(1) not null default '0', `is_lost` tinyint(1) not null default '0', `probability` tinyint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `pipeline_stages` add index `pipeline_stages_pipeline_id_sort_order_index`(`pipeline_id`, `sort_order`);
alter table `pipeline_stages` add index `pipeline_stages_workspace_id_index`(`workspace_id`);

-- ============================================================
-- 2026_06_13_100200_create_deals_table
-- ============================================================
create table `deals` (`id` bigint unsigned not null auto_increment primary key, `workspace_id` bigint unsigned not null, `pipeline_id` bigint unsigned not null, `stage_id` bigint unsigned not null, `contact_id` bigint unsigned null, `conversation_id` bigint unsigned null, `title` varchar(191) not null, `value_minor` bigint not null default '0', `currency` varchar(10) not null default 'INR', `owner_user_id` bigint unsigned null, `owner_team_id` bigint unsigned null, `expected_close_date` date null, `status` varchar(16) not null default 'open', `lost_reason` varchar(191) null, `source` varchar(24) not null default 'manual', `sort_order` int unsigned not null default '0', `notes` text null, `meta` json null, `won_at` timestamp null, `lost_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `deals` add index `deals_workspace_id_status_index`(`workspace_id`, `status`);
alter table `deals` add index `deals_pipeline_id_stage_id_index`(`pipeline_id`, `stage_id`);
alter table `deals` add index `deals_contact_id_index`(`contact_id`);
alter table `deals` add index `deals_owner_user_id_index`(`owner_user_id`);

-- ============================================================
-- 2026_06_13_100300_create_deal_activities_table
-- ============================================================
create table `deal_activities` (`id` bigint unsigned not null auto_increment primary key, `deal_id` bigint unsigned not null, `workspace_id` bigint unsigned not null, `user_id` bigint unsigned null, `type` varchar(24) not null default 'note', `body` text null, `meta` json null, `due_at` timestamp null, `done_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `deal_activities` add index `deal_activities_deal_id_created_at_index`(`deal_id`, `created_at`);
alter table `deal_activities` add index `deal_activities_workspace_id_type_index`(`workspace_id`, `type`);
alter table `deal_activities` add index `deal_activities_due_at_index`(`due_at`);

-- ============================================================
-- 2026_06_13_100400_add_sales_pipeline_to_packages
-- ============================================================
alter table `packages` add `access_sales_pipeline` tinyint(1) not null default '0';
alter table `packages` add `pipelines_limit` int unsigned null;
update `packages` set `access_sales_pipeline` = 1 where `is_highlighted` = 1;

-- ============================================================
-- 2026_06_13_100500_add_deal_automation_to_workspaces
-- ============================================================
alter table `workspaces` add `deals_auto_from_orders` tinyint(1) not null default '0';
alter table `workspaces` add `deals_auto_min_minor` bigint null;

-- ============================================================
-- 2026_06_13_130000_seed_remaining_admin_ai_keys
-- ============================================================
-- Idempotent: 1.0's AdminAiKeySeeder already seeds these providers on most
-- installs, so insert each ONLY when the row is missing (mirrors the
-- migration's firstOrCreate guard; pretend dropped it because its existence
-- check returned empty against the scratch DB).
INSERT INTO `admin_ai_keys` (`provider`,`name`,`api_key`,`default_model`,`extra_config`,`is_active`,`sort_order`,`created_at`,`updated_at`)
  SELECT 'openai','OpenAI','','gpt-5.4-mini','[]',0,1,NOW(),NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `admin_ai_keys` WHERE `provider`='openai');
INSERT INTO `admin_ai_keys` (`provider`,`name`,`api_key`,`default_model`,`extra_config`,`is_active`,`sort_order`,`created_at`,`updated_at`)
  SELECT 'anthropic','Anthropic Claude','','claude-opus-4-8','[]',0,2,NOW(),NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `admin_ai_keys` WHERE `provider`='anthropic');
INSERT INTO `admin_ai_keys` (`provider`,`name`,`api_key`,`default_model`,`extra_config`,`is_active`,`sort_order`,`created_at`,`updated_at`)
  SELECT 'gemini','Google Gemini','','gemini-3.5-flash','[]',0,3,NOW(),NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `admin_ai_keys` WHERE `provider`='gemini');
INSERT INTO `admin_ai_keys` (`provider`,`name`,`api_key`,`default_model`,`extra_config`,`is_active`,`sort_order`,`created_at`,`updated_at`)
  SELECT 'mistral','Mistral','','mistral-large-latest','[]',0,4,NOW(),NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `admin_ai_keys` WHERE `provider`='mistral');
update `admin_ai_keys` set `sort_order` = 5 where `provider` = 'elevenlabs';

-- ============================================================
-- 2026_06_14_090000_add_reminded_at_to_deal_activities
-- ============================================================
alter table `deal_activities` add `reminded_at` timestamp null after `done_at`;

-- ============================================================
-- 2026_06_15_120000_add_multi_engine_to_workspaces
-- ============================================================
alter table `workspaces` add `enabled_engines` json null after `plan`;
alter table `workspaces` add `default_engine` varchar(16) null after `enabled_engines`;

-- ============================================================
-- 2026_06_16_000100_add_channel_to_wa_templates
-- ============================================================
alter table `wa_templates` add `channel` varchar(16) null after `meta_status`;
alter table `wa_templates` add index `wa_templates_channel_index`(`channel`);
update `wa_templates` set `channel` = 'twilio' where `twilio_content_sid` is not null and `twilio_content_sid` != '';
update `wa_templates` set `channel` = 'waba' where `channel` is null and (`meta_template_id` is not null or `provider_config_id` is not null);
update `wa_templates` set `channel` = 'baileys' where `channel` is null;

-- ============================================================
-- 2026_06_16_010000_add_throttle_to_wp_campaigns
-- ============================================================
alter table `wpcampaigns` add `throttle_min_sec` smallint unsigned null after `repeat_until`;
alter table `wpcampaigns` add `throttle_max_sec` smallint unsigned null after `throttle_min_sec`;
alter table `wpcampaigns` add `batch_size` smallint unsigned null after `throttle_max_sec`;
alter table `wpcampaigns` add `batch_pause_min` smallint unsigned null after `batch_size`;
alter table `wpcampaigns` add `daily_limit` int unsigned null after `batch_pause_min`;
alter table `wpcampaigns` add `window_start` varchar(5) null after `daily_limit`;
alter table `wpcampaigns` add `window_end` varchar(5) null after `window_start`;

-- ============================================================
-- 2026_06_17_000000_create_incoming_webhooks_tables
-- ============================================================
create table `incoming_webhooks` (`id` bigint unsigned not null auto_increment primary key, `workspace_id` bigint unsigned not null, `user_id` bigint unsigned null, `name` varchar(128) null, `token` varchar(64) not null, `forward_url` text null, `forward_enabled` tinyint(1) not null default '0', `is_active` tinyint(1) not null default '1', `received_count` bigint unsigned not null default '0', `last_received_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `incoming_webhooks` add index `incoming_webhooks_workspace_id_index`(`workspace_id`);
alter table `incoming_webhooks` add index `incoming_webhooks_user_id_index`(`user_id`);
alter table `incoming_webhooks` add unique `incoming_webhooks_token_unique`(`token`);
alter table `incoming_webhooks` add index `incoming_webhooks_is_active_index`(`is_active`);
create table `incoming_webhook_events` (`id` bigint unsigned not null auto_increment primary key, `incoming_webhook_id` bigint unsigned not null, `method` varchar(8) not null default 'POST', `source_ip` varchar(45) null, `content_type` varchar(191) null, `headers` longtext null, `payload` longtext null, `forwarded` tinyint(1) not null default '0', `forward_status` smallint unsigned null, `forward_error` text null, `received_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `incoming_webhook_events` add index `incoming_webhook_events_incoming_webhook_id_index`(`incoming_webhook_id`);
alter table `incoming_webhook_events` add index `incoming_webhook_events_received_at_index`(`received_at`);

-- ============================================================
-- 2026_06_18_000000_add_retry_to_wp_campaign_contacts
-- ============================================================
alter table `wp_campaign_contacts` add `send_attempts` tinyint unsigned not null default '0' after `status`;
alter table `wp_campaign_contacts` add `next_attempt_at` timestamp null after `send_attempts`;
alter table `wp_campaign_contacts` add index `wp_campaign_contacts_next_attempt_at_index`(`next_attempt_at`);

-- ============================================================
-- 2026_06_18_000100_add_ab_variant_columns_to_wp_campaigns
-- ============================================================
alter table `wpcampaigns` add `custom_message_b` text null after `custom_message`;

-- ============================================================
-- 2026_06_18_000200_create_addon_packages_and_retention
-- ============================================================
alter table `packages` add `type` varchar(16) not null default 'plan' after `plan_id`;
alter table `packages` add `data_retention_days` int unsigned not null default '0' after `lifetime`;
alter table `packages` add index `packages_type_index`(`type`);
create table `workspace_addons` (`id` bigint unsigned not null auto_increment primary key, `workspace_id` bigint unsigned not null, `package_id` bigint unsigned not null, `order_id` bigint unsigned null, `status` varchar(16) not null default 'active', `starts_at` timestamp null, `ends_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `workspace_addons` add index `workspace_addons_workspace_id_status_index`(`workspace_id`, `status`);
alter table `workspace_addons` add index `workspace_addons_workspace_id_index`(`workspace_id`);
alter table `workspace_addons` add index `workspace_addons_package_id_index`(`package_id`);

-- ============================================================
-- 2026_06_18_000300_add_data_wiped_at_to_workspaces
-- ============================================================
alter table `workspaces` add `data_wiped_at` timestamp null;
alter table `workspaces` add index `workspaces_data_wiped_at_index`(`data_wiped_at`);

-- ============================================================
-- 2026_06_18_000400_add_retry_to_scheduled_messages
-- ============================================================
alter table `scheduled_messages` add `send_attempts` tinyint unsigned not null default '0' after `status`;
alter table `scheduled_messages` add `next_attempt_at` timestamp null after `send_attempts`;
alter table `scheduled_messages` add `last_error` text null after `next_attempt_at`;

-- ============================================================
-- 2026_06_18_000500_add_retry_to_broadcasts
-- ============================================================
alter table `broadcasts` add `send_attempts` tinyint unsigned not null default '0' after `status`;
alter table `broadcasts` add `next_attempt_at` timestamp null after `send_attempts`;

-- ============================================================
-- 2026_06_18_010000_bump_anthropic_default_to_opus_4_8
-- ============================================================
update `admin_ai_keys` set `default_model` = 'claude-opus-4-8' where `provider` = 'anthropic' and `default_model` = 'claude-opus-4-7';

-- ============================================================
-- 2026_06_18_020000_refresh_stale_admin_ai_default_models
-- ============================================================

-- ============================================================
-- Data refresh: admin AI keys -> current model defaults
-- (2026_06_18_020000_refresh_stale_admin_ai_default_models — a read-then-
--  write migration pretend cannot capture). Only moves rows still on a
--  retired/old default; never clobbers a deliberate working choice.
-- ============================================================
UPDATE `admin_ai_keys` SET `default_model` = 'claude-opus-4-8' WHERE `provider` = 'anthropic' AND (`is_active` = 0 OR `default_model` IN ('claude-3-5-sonnet-latest','claude-3-5-sonnet','claude-3-5-sonnet-20240620','claude-3-5-sonnet-20241022','claude-3-opus','claude-3-opus-20240229','claude-opus-4-7','claude-opus-4-6'));
UPDATE `admin_ai_keys` SET `default_model` = 'gemini-3.5-flash' WHERE `provider` = 'gemini' AND (`is_active` = 0 OR `default_model` IN ('gemini-1.5-pro','gemini-1.5-flash','gemini-1.0-pro','gemini-pro','gemini-2.0-flash-exp'));
UPDATE `admin_ai_keys` SET `default_model` = 'gpt-5.4-mini' WHERE `provider` = 'openai' AND (`is_active` = 0 OR `default_model` IN ('gpt-3.5-turbo','gpt-4','gpt-4-turbo','gpt-4o-mini'));
UPDATE `admin_ai_keys` SET `default_model` = 'eleven_v3' WHERE `provider` = 'elevenlabs' AND `is_active` = 0;

-- ============================================================
-- Mark these migrations as run so `php artisan migrate` is a no-op later.
-- ============================================================
SET @b := (SELECT IFNULL(MAX(`batch`),0)+1 FROM `migrations`);
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_06_10_000100_add_app_api_auth_columns', @b),
  ('2026_06_10_063140_create_personal_access_tokens_table', @b),
  ('2026_06_10_100000_create_slack_integrations_table', @b),
  ('2026_06_10_100100_create_trello_integrations_table', @b),
  ('2026_06_10_100200_add_slack_trello_plan_flags', @b),
  ('2026_06_11_120000_add_pinned_to_broadcasts_table', @b),
  ('2026_06_12_000000_add_header_location_to_wa_templates', @b),
  ('2026_06_12_120000_add_content_to_broadcasts_table', @b),
  ('2026_06_13_000100_create_api_keys_table', @b),
  ('2026_06_13_100000_create_pipelines_table', @b),
  ('2026_06_13_100100_create_pipeline_stages_table', @b),
  ('2026_06_13_100200_create_deals_table', @b),
  ('2026_06_13_100300_create_deal_activities_table', @b),
  ('2026_06_13_100400_add_sales_pipeline_to_packages', @b),
  ('2026_06_13_100500_add_deal_automation_to_workspaces', @b),
  ('2026_06_13_130000_seed_remaining_admin_ai_keys', @b),
  ('2026_06_14_090000_add_reminded_at_to_deal_activities', @b),
  ('2026_06_15_120000_add_multi_engine_to_workspaces', @b),
  ('2026_06_16_000100_add_channel_to_wa_templates', @b),
  ('2026_06_16_010000_add_throttle_to_wp_campaigns', @b),
  ('2026_06_17_000000_create_incoming_webhooks_tables', @b),
  ('2026_06_18_000000_add_retry_to_wp_campaign_contacts', @b),
  ('2026_06_18_000100_add_ab_variant_columns_to_wp_campaigns', @b),
  ('2026_06_18_000200_create_addon_packages_and_retention', @b),
  ('2026_06_18_000300_add_data_wiped_at_to_workspaces', @b),
  ('2026_06_18_000400_add_retry_to_scheduled_messages', @b),
  ('2026_06_18_000500_add_retry_to_broadcasts', @b),
  ('2026_06_18_010000_bump_anthropic_default_to_opus_4_8', @b),
  ('2026_06_18_020000_refresh_stale_admin_ai_default_models', @b);

SET FOREIGN_KEY_CHECKS = 1;
