<?php

namespace Blc\Database;

use Blc\Util\ConfigurationManager;

class DatabaseUpgrader
{
    /**
     * Create and/or upgrade the plugin's database tables.
     *
     * @return bool
     */
    public static function upgrade_database()
    {
        global $blclog;

        $plugin_config    = ConfigurationManager::getInstance();
        $current = $plugin_config->options['current_db_version'];

        if (( 0 != $current ) && ( $current < 17 )) {
            // The 4th DB version makes a lot of backwards-incompatible changes to the main
            // BLC tables, so instead of upgrading we just throw them away and recreate.
            if (! self::drop_tables()) {
                return false;
            }
            $current = 0;
        }

        // Create/update the plugin's tables
        if (! self::make_schema_current()) {
            return false;
        }

        $plugin_config->options['current_db_version'] = BLC_DATABASE_VERSION;
        $plugin_config->save_options();
        $blclog->info('Database successfully upgraded.');

        return true;
    }

    /**
     * Create or update the plugin's DB tables.
     *
     * @return bool
     */
    static function make_schema_current()
    {
        global $blclog;

        $start = microtime(true);

      

        list($dummy, $query_log) = TableDelta::delta(self::blc_get_db_schema());

        $have_errors = false;
        foreach ($query_log as $item) {
            if ($item['success']) {
                $blclog->info(' [OK] ' . $item['query'] . sprintf(' (%.3f seconds)', $item['query_time']));
            } else {
                $blclog->error(' [  ] ' . $item['query']);
                $blclog->error(' Database error : ' . $item['error_message']);
                $have_errors = true;
            }
        }
        $blclog->info(sprintf('Schema update took %.3f seconds', microtime(true) - $start));

        $blclog->info('Database schema updated.');
        return ! $have_errors;
    }

    /**
     * Drop the plugin's tables.
     *
     * @return bool
     */
    static function drop_tables()
    {
        global $wpdb, $blclog;
        /** @var wpdb $wpdb */

        $blclog->info('Deleting the plugin\'s database tables');
        $tables = array(
            $wpdb->prefix . 'blc_linkdata',
            $wpdb->prefix . 'blc_postdata',
            $wpdb->prefix . 'blc_instances',
            $wpdb->prefix . 'blc_synch',
            $wpdb->prefix . 'blc_links',
        );

        $q   = 'DROP TABLE IF EXISTS ' . implode(', ', $tables);
        $rez = $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if (false === $rez) {
            $error = sprintf(
                __('Failed to delete old DB tables. Database error : %s', 'broken-link-checker'),
                $wpdb->last_error
            );

            $blclog->error($error);
            /*
            //FIXME: In very rare cases, DROP TABLE IF EXISTS throws an error when the table(s) don't exist.
            return false;
            //*/
        }
        $blclog->info('Done.');

        return true;
    }
    


    static function wpmudev_blc_local_get_charset_collate()
    {
        global $wpdb;

        // Let's make sure that new tables collate will match with the one set in posts table (and specifically post_status, since upon synch there's a join with that column).
        // Reason for this is to avoid getting the `Illegal mix of collations` error.
        // Root of this issue is that older WP versions (prior to 4.6) had different default collation ( utf8mb4_unicode_ci ) and versions from 4.6 and after use `utf8mb4_unicode_520_ci`.

        $collate         = self::get_table_col_collation($wpdb->posts, 'post_type');
        $charset         = self::get_table_col_charset($wpdb->posts, 'post_type');
        $charset_collate = '';

        if (! empty($collate) && ! empty($charset)) {
            $charset_collate = "DEFAULT CHARACTER SET {$charset} COLLATE {$collate}";
        } else {
            if (! empty($wpdb->charset)) {
                // Some German installs use "utf-8" (invalid) instead of "utf8" (valid). None of
                // the charset ids supported by MySQL contain dashes, so we can safely strip them.
                // See http://dev.mysql.com/doc/refman/5.0/en/charset-charsets.html
                $charset = str_replace('-', '', $wpdb->charset);

                // set charset
                $charset_collate = "DEFAULT CHARACTER SET {$charset}";
            }

            if (! empty($wpdb->collate)) {
                $charset_collate .= " COLLATE {$wpdb->collate}";
            }
        }

        return $charset_collate;
    }

    /**
     * Get collation of a table's column.
     *
     * @since 2.2.2
     *
     * @param string $table The table name
     * @param string $column The table's column name
     * @return null|string
     */
    static function get_table_col_collation(string $table = '', string $column = '')
    {
        if (empty($table) || empty($column)) {
            return null;
        }

        $table_parts = explode('.', $table);
        $table       = ! empty($table_parts[1]) ? $table_parts[1] : $table;
        $col_key     = strtolower("{$table}_{$column}");

        static $tables_collates = array();

        if (! isset($tables_collates[ $col_key ])) {
            global $wpdb;

            $tables_collates[ $col_key ] = null;
            $table_status                = null;

            // Alternatively in order to check only for wp core tables $wpdb->tables() could be used.
            $tables_like_table = $wpdb->get_results($wpdb->prepare('SHOW TABLES LIKE %s', $table));

            if (! empty($tables_like_table)) {
                $table_status = $wpdb->get_row(
                    $wpdb->prepare(
                        "SHOW FULL COLUMNS FROM {$table} WHERE field = '%s'",
                        $column
                    )
                );
            }

            if (! empty($table_status) && ! empty($table_status->Collation)) {
                $tables_collates[ $col_key ] = $table_status->Collation;
            }
        }

        return $tables_collates[ $col_key ];
    }

    /**
     * Get charset of a table's column.
     *
     * @since 2.2.2
     *
     * @param string $table The table name
     * @param string $column The table's column name
     * @return null|string
     */
    static function get_table_col_charset(string $table = '', string $column = '')
    {
        if (empty($table) || empty($column)) {
            return null;
        }

        $collation = self::get_table_col_collation($table, $column);

        if (empty($collation)) {
            return null;
        }

        list($charset) = explode('_', $collation);

        return $charset;
    }




    static function blc_get_db_schema()
    {
        global $wpdb;

        $charset_collate = self::wpmudev_blc_local_get_charset_collate();

        $blc_db_schema = <<<EOM

	CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}blc_filters` (
		`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`name` varchar(100) NOT NULL,
		`params` text NOT NULL,

		PRIMARY KEY (`id`)
	) {$charset_collate};

	CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}blc_instances` (
		`instance_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`link_id` int(10) unsigned NOT NULL,
		`container_id` int(10) unsigned NOT NULL,
		`container_type` varchar(40) NOT NULL DEFAULT 'post',
		`link_text` text NOT NULL DEFAULT '',
		`parser_type` varchar(40) NOT NULL DEFAULT 'link',
		`container_field` varchar(250) NOT NULL DEFAULT '',
		`link_context` varchar(250) NOT NULL DEFAULT '',
		`raw_url` text NOT NULL,

		PRIMARY KEY (`instance_id`),
		KEY `link_id` (`link_id`),
		KEY `source_id` (`container_type`, `container_id`),
		KEY `lpc` (`link_id`,`parser_type`,`container_type`),
		KEY `parser_type` (`parser_type`)
	) {$charset_collate};

	CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}blc_links` (
		`link_id` int(20) unsigned NOT NULL AUTO_INCREMENT,
		`url` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
		`first_failure` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		`last_check` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		`last_success` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		`last_check_attempt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		`check_count` int(4) unsigned NOT NULL DEFAULT '0',
		`final_url` text CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
		`redirect_count` smallint(5) unsigned NOT NULL DEFAULT '0',
		`log` text NOT NULL,
		`http_code` smallint(6) NOT NULL DEFAULT '0',
		`status_code` varchar(100) DEFAULT '',
		`status_text` varchar(250) DEFAULT '',
		`request_duration` float NOT NULL DEFAULT '0',
		`timeout` tinyint(1) unsigned NOT NULL DEFAULT '0',
		`broken` tinyint(1) unsigned NOT NULL DEFAULT '0',
		`warning` tinyint(1) unsigned NOT NULL DEFAULT '0',
		`may_recheck` tinyint(1) NOT NULL DEFAULT '1',
		`being_checked` tinyint(1) NOT NULL DEFAULT '0',
		`parked` tinyint(1) NOT NULL DEFAULT '0',

		`result_hash` varchar(200) NOT NULL DEFAULT '',
		`false_positive` tinyint(1) NOT NULL DEFAULT '0',
		`dismissed` tinyint(1) NOT NULL DEFAULT '0',

		PRIMARY KEY (`link_id`),
		KEY `url` (`url`(150)),
		KEY `final_url` (`final_url`(150)),
		KEY `http_code` (`http_code`),
		KEY `broken` (`broken`),
		KEY `last_check_attempt` (`last_check_attempt`),
		KEY `may_recheck` (`may_recheck`),
		KEY `check_count` (`check_count`),
		KEY `parked` (`parked`)

	) {$charset_collate};

	CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}blc_synch` (
		`container_id` int(20) unsigned NOT NULL,
		`container_type` varchar(40) NOT NULL,
		`synched` tinyint(2) unsigned NOT NULL,
		`last_synch` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',

		PRIMARY KEY (`container_type`,`container_id`),
		KEY `synched` (`synched`)
	) {$charset_collate};

EOM;

        return $blc_db_schema;
    }



}

