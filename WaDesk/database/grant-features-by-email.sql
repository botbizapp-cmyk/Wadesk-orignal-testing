-- ============================================================================
-- WaDesk — grant ALL features to ONE user (by email) + seed Sales-Pipeline data
--
-- SCHEMA-TOLERANT: it only touches columns/tables that actually EXIST in the
-- target database, so it never errors with "Unknown column" even on an older
-- schema. You set ONLY the email below; it auto-resolves that user's workspace
-- and plan, turns on every feature flag the plan has, enables multi-engine +
-- auto-deals (if those columns exist), and seeds a pipeline + stages + 3 sample
-- deals (only if the 1.1 pipeline tables exist).
--
-- RUN ORDER: if you also want the sample pipeline data, apply `1.1.sql` FIRST
-- (it creates the deals/pipelines tables + new columns). The feature-flag part
-- works on any schema version either way.
--
-- HOW TO RUN: paste the WHOLE file into ONE SQL session (phpMyAdmin "SQL" tab,
-- Adminer, or `mysql < file`). It uses @session variables + PREPARE, so it must
-- run as one script, not statement-by-statement across connections.
--
-- NOTE: feature flags live on the PLAN (packages row) — enabling them affects
-- every workspace on that same plan.
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;   -- match table collation (no "illegal mix")
SET SESSION group_concat_max_len = 100000;       -- don't truncate the column list

-- ============ CONFIG — change only this line ============
SET @email := 'user@mediacity.co.in';
-- ========================================================

-- Auto-resolve email -> user -> workspace -> package.
SET @uid   := (SELECT `id` FROM `users` WHERE `email` = @email ORDER BY `id` LIMIT 1);
SET @ws    := (SELECT `current_workspace_id` FROM `users` WHERE `id` = @uid LIMIT 1);
SET @plan  := (SELECT `plan` FROM `workspaces` WHERE `id` = @ws LIMIT 1);
SET @pkgid := (SELECT `id` FROM `packages` WHERE `id` = @plan OR `plan_id` = @plan OR `pname` = @plan ORDER BY `id` LIMIT 1);
SELECT @email AS email, @uid AS user_id, @ws AS workspace_id, @pkgid AS package_id;

-- ----------------------------------------------------------------------------
-- 1) PACKAGES — turn ON every boolean feature flag that EXISTS in this schema.
-- ----------------------------------------------------------------------------
SET @cols := (SELECT GROUP_CONCAT(CONCAT('`', COLUMN_NAME, '`=1') SEPARATOR ',')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'packages'
      AND ( COLUMN_NAME LIKE 'access\_%'
         OR COLUMN_NAME LIKE 'integration\_%'
         OR COLUMN_NAME IN ('autoreply','bulkmessage','schedulemessage','ads','campaign',
                            'autoflow','broadcast','template','multipledevice','chatgpt_suggestion',
                            'remove_branding','role_based_permissions','allow_byok_ai_keys') ));
SET @sql := IF(@cols IS NULL OR @pkgid IS NULL, 'DO 0', CONCAT('UPDATE `packages` SET ', @cols, ' WHERE `id`=', @pkgid));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- 2) WORKSPACE — set each new (1.1) column only if it exists.
-- ----------------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspaces' AND COLUMN_NAME='enabled_engines')>0,
    CONCAT('UPDATE `workspaces` SET `enabled_engines`=JSON_ARRAY(''baileys'',''waba'',''twilio'') WHERE `id`=', @ws), 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspaces' AND COLUMN_NAME='default_engine')>0,
    CONCAT('UPDATE `workspaces` SET `default_engine`=COALESCE(`default_engine`,''baileys'') WHERE `id`=', @ws), 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspaces' AND COLUMN_NAME='deals_auto_from_orders')>0,
    CONCAT('UPDATE `workspaces` SET `deals_auto_from_orders`=1 WHERE `id`=', @ws), 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- 3) SALES PIPELINE SEED — only if the 1.1 tables exist (idempotent).
-- ----------------------------------------------------------------------------
SET @has_p := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pipelines');

-- a) default pipeline
SET @sql := IF(@has_p>0,
    'INSERT INTO `pipelines` (`workspace_id`,`name`,`is_default`,`currency`,`sort_order`,`created_at`,`updated_at`) SELECT @ws,''Sales Pipeline'',1,''INR'',0,NOW(),NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `pipelines` WHERE `workspace_id`=@ws)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @pl := NULL;
SET @sql := IF(@has_p>0, 'SET @pl := (SELECT `id` FROM `pipelines` WHERE `workspace_id`=@ws ORDER BY `is_default` DESC,`id` ASC LIMIT 1)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- b) stages (only if this pipeline has none)
SET @sql := IF(@has_p>0 AND @pl IS NOT NULL,
    'INSERT INTO `pipeline_stages` (`pipeline_id`,`workspace_id`,`name`,`sort_order`,`color`,`is_won`,`is_lost`,`probability`,`created_at`,`updated_at`) SELECT * FROM (SELECT @pl AS a,@ws AS b,''New Lead'' AS c,0 AS d,''#25D366'' AS e,0 AS f,0 AS g,10 AS h,NOW() AS i,NOW() AS j UNION ALL SELECT @pl,@ws,''Qualified'',1,''#1FA855'',0,0,30,NOW(),NOW() UNION ALL SELECT @pl,@ws,''Proposal'',2,''#F2A33C'',0,0,60,NOW(),NOW() UNION ALL SELECT @pl,@ws,''Won'',3,''#0E7C5A'',1,0,100,NOW(),NOW() UNION ALL SELECT @pl,@ws,''Lost'',4,''#C0432A'',0,1,0,NOW(),NOW()) s WHERE NOT EXISTS (SELECT 1 FROM `pipeline_stages` WHERE `pipeline_id`=@pl)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- c) resolve stage ids
SET @s_lead := NULL; SET @s_qual := NULL; SET @s_won := NULL;
SET @sql := IF(@has_p>0 AND @pl IS NOT NULL, 'SET @s_lead := (SELECT `id` FROM `pipeline_stages` WHERE `pipeline_id`=@pl AND `is_won`=0 AND `is_lost`=0 ORDER BY `sort_order` ASC LIMIT 1)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@has_p>0 AND @pl IS NOT NULL, 'SET @s_qual := (SELECT `id` FROM `pipeline_stages` WHERE `pipeline_id`=@pl AND `is_won`=0 AND `is_lost`=0 ORDER BY `sort_order` ASC LIMIT 1 OFFSET 1)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@has_p>0 AND @pl IS NOT NULL, 'SET @s_won := (SELECT `id` FROM `pipeline_stages` WHERE `pipeline_id`=@pl AND `is_won`=1 ORDER BY `sort_order` ASC LIMIT 1)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- d) 3 sample deals (value_minor in paise/cents; 4500000 = 45,000.00)
SET @sql := IF(@has_p>0 AND @pl IS NOT NULL,
    'INSERT INTO `deals` (`workspace_id`,`pipeline_id`,`stage_id`,`title`,`value_minor`,`currency`,`owner_user_id`,`status`,`source`,`sort_order`,`won_at`,`created_at`,`updated_at`) SELECT * FROM (SELECT @ws AS a,@pl AS b,@s_lead AS c,''Acme Corp - bulk WhatsApp plan'' AS d,4500000 AS e,''INR'' AS f,@uid AS g,''open'' AS h,''manual'' AS i,0 AS j,NULL AS k,NOW() AS l,NOW() AS m UNION ALL SELECT @ws,@pl,@s_qual,''Bright Retail - onboarding'',1200000,''INR'',@uid,''open'',''manual'',1,NULL,NOW(),NOW() UNION ALL SELECT @ws,@pl,@s_won,''Sunrise Clinic - annual deal'',9900000,''INR'',@uid,''won'',''manual'',2,NOW(),NOW(),NOW()) d WHERE NOT EXISTS (SELECT 1 FROM `deals` WHERE `workspace_id`=@ws AND `source`=''manual'' AND `title` LIKE ''Acme Corp%'')', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Readback
SELECT @pkgid AS plan_updated, @ws AS workspace, @has_p AS pipeline_tables_present, @pl AS pipeline_id;
