<?php
#namespace Habari;

/**
 * @package staticcache
 *
 */

/**
 * StaticCache Plugin will cache the HTML output generated by Habari for each page.
 */
class StaticCache extends Plugin
{
	const VERSION = 0.3;
	const API_VERSION = 004;

	const GZ_COMPRESSION = 4;
	const EXPIRE = 86400;
	const EXPIRE_STATS = 604800;

	const GROUP_NAME = 'staticcache';
	const STATS_GROUP_NAME = 'staticcache_stats';

	/**
	 * Set a priority of 1 on action_init so we run first
	 *
	 * @return array the priorities for our hooks
	 */
	public function set_priorities()
	{
		return array(
			'action_init' => 1
			);
	}

	/**
	 * Create aliases to additional hooks
	 *
	 * @return array aliased hooks
	 */
	public function alias()
	{
		return array(
			'action_post_update_after' => array(
				'action_post_insert_after',
				'action_post_delete_after'
			),
			'action_comment_update_after' => array(
				'action_comment_insert_after',
				'action_comment_delete_after'
			)
		);
	}

	/**
	 * Serves the cache page or starts the output buffer. Ignore URLs matching
	 * the ignore list, and ignores if there are session messages.
	 *
	 * @see StaticCache_ob_end_flush()
	 */
	public function action_init()
	{
		/**
		 * Allows plugins to add to the ignore list. An array of all URLs to ignore
		 * is passed to the filter.
		 *
		 * @filter staticcache_ignore an array of URLs to ignore
		 */
		$ignore_array = Plugins::filter(
			'staticcache_ignore',
			explode(',', Options::get('staticcache__ignore_list' ))
		);

		// sanitize the ignore list for preg_match
		$ignore_list = implode(
			'|',
			array_map(
				create_function('$a', 'return preg_quote(trim($a), "@");'),
				$ignore_array
			)
		);
		$request = Site::get_url('host') . $_SERVER['REQUEST_URI'];
		$request_method = $_SERVER['REQUEST_METHOD'];

		/* don't cache PUT or POST requests, pages matching ignore list keywords,
		 * nor pages with session messages
		 */
		if ( $request_method == 'PUT' || $request_method == 'POST'
			|| preg_match("@.*($ignore_list).*@i", $request) || Session::has_messages() ) {
			return;
		}

		$request_id = self::get_request_id();
		$query_id = self::get_query_id();

		if ( Cache::has(array(self::GROUP_NAME, $request_id)) ) {
			$cache = Cache::get( array(self::GROUP_NAME, $request_id) );
			if ( isset( $cache[$query_id] ) ) {
				global $profile_start;

				// send the cached headers
				foreach( $cache[$query_id]['headers'] as $header ) {
					header($header);
				}
				// check for compression
				if ( isset($cache[$query_id]['compressed']) && $cache[$query_id]['compressed'] == true ) {
					echo gzuncompress($cache[$query_id]['body']);
				}
				else {
					echo $cache[$query_id]['body'];
				}
				// record hit and profile data
				$this->record_stats('hit', $profile_start);
				exit;
			}
		}
		// record miss
		$this->record_stats('miss');
		// register hook
		Plugins::register(array('StaticCache', 'store_final_output'), 'filter', 'final_output', 16);
		//ob_start('StaticCache_ob_end_flush');
	}

	/**
	 * Record StaticCaches stats in the cache itself to avoid DB writes.
	 * Data includes hits, misses, and avg.
	 *
	 * @param string $type type of record, either hit or miss
	 * @param double $profile_start start of the profiling
	 */
	protected function record_stats( $type, $profile_start = null )
	{
		switch ( $type ) {
			case 'hit':
				// do stats and output profiling
				$pagetime = microtime(true) - $profile_start;
				$hits = (int) Cache::get(array(self::STATS_GROUP_NAME, 'hits'));
				$profile = (double) Cache::get(array(self::STATS_GROUP_NAME, 'avg'));
				$avg = ($profile * $hits + $pagetime) / ($hits + 1);
				Cache::set( array(self::STATS_GROUP_NAME, 'avg'), $avg, self::EXPIRE_STATS );
				Cache::set( array(self::STATS_GROUP_NAME, 'hits'), $hits + 1, self::EXPIRE_STATS );
				// @todo add option to have output or not
				//echo '<!-- ' , _t( 'Served by StaticCache in %s seconds', array($pagetime), 'staticcache' ) , ' -->';
				header('X-StaticCache-Stats: '.$pagetime);
				break;
			case 'miss':
				Cache::set( array(self::STATS_GROUP_NAME, 'misses'), Cache::get(array(self::STATS_GROUP_NAME, 'misses')) + 1, self::EXPIRE_STATS );
				break;
		}
	}

	/**
	 * Add the Static Cache dashboard module
	 *
	 * @param array $modules Available dash modules
	 * @return array modules array
	 */
	public function filter_dashboard_block_list($block_list)
	{
		$block_list['staticcache'] = 'Static Cache';
		$this->add_template( 'dashboard.block.staticcache', dirname( __FILE__ ) . '/dashboard.block.staticcache.php' );
		return $block_list;
	}

	/**
	 * Filters the static cache dash module to add the theme template output.
	 *
	 * @param Block $block the dashboard block
	 * @param Theme the current theme from the handler
	 * @return array the modified module structure
	 */
	public function action_block_content_staticcache( Block $block, Theme $theme )
	{
		if ( Options::get( 'staticcache__cache_method' ) == 'habari' ) {
			$block->static_cache_average = sprintf( '%.4f', Cache::get(array(self::STATS_GROUP_NAME, 'avg')) );
			$block->static_cache_pages = count(Cache::get_group(self::GROUP_NAME));

			$hits = Cache::get(array(self::STATS_GROUP_NAME, 'hits'));
			$misses = Cache::get(array(self::STATS_GROUP_NAME, 'misses'));
			$total = $hits + $misses;
			$block->static_cache_hits_pct = sprintf('%.0f', $total > 0 ? ($hits/$total)*100 : 0);
			$block->static_cache_misses_pct = sprintf('%.0f', $total > 0 ? ($misses/$total)*100 : 0);
			$block->static_cache_hits = $hits;
			$block->static_cache_misses = $misses;
		}
	}

	/**
	 * Ajax entry point for the 'clear cache data' action. Clears all stats and cache data
	 * and outputs a JSON encoded string message.
	 */
	public function action_auth_ajax_clear_staticcache()
	{
		self::clear_staticcache();
		echo json_encode(_t( "Cleared Static Cache's cache" ) );
	}

	/**
	 * Function to clear out the entire cache
	 *
	 */
	private static function clear_staticcache()
	{
		if ( Options::get( 'staticcache__cache_method' ) == 'htaccess' ) {
			$cache_dir = HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/' . $_SERVER['SERVER_NAME'] . Site::get_path( 'habari' );
			self::rrmdir( $cache_dir );
		} 
		else {
			foreach ( Cache::get_group(self::GROUP_NAME) as $name => $data ) {
				Cache::expire( array(self::GROUP_NAME, $name) );
			}
			foreach ( Cache::get_group(self::STATS_GROUP_NAME) as $name => $data ) {
				Cache::expire( array(self::STATS_GROUP_NAME, $name) );
			}
		}
		EventLog::log( _t( "Cleared cache" ), 'info' );
	}

	/**
	 * Invalidates (expires) the cache entries for the given list of URLs.
	 *
	 * @param array $urls An array of urls to clear
	 */
	public function cache_invalidate( array $urls )
	{
		if ( Options::get('staticcache__cache_method') == 'htaccess' ) {
			# Delete files
			foreach( $urls as $url ) {
				# convert URL to file path
				$file = preg_replace( '#^http(s)?://#', '', $url );

				if ( file_exists( HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/' . $file ) ) {
					array_map( 'unlink', glob( HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/' . $file . "/index.*" ) );
					if ( $url != Site::get_url('habari') ) {
						@rmdir( HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/' . $file . '/' );
					}
				}
			}
		}
		else {
			// account for annonymous user (id=0)
			$user_ids = array_map( create_function('$a', 'return $a->id;'), Users::get_all()->getArrayCopy() );
			array_push($user_ids, "0");

			// expire the urls for each user id
			foreach ( $user_ids as $user_id ) {
				foreach( $urls as $url ) {
					$request_id = self::get_request_id( $user_id, $url );
					if ( Cache::has(array(self::GROUP_NAME, $request_id)) ) {
						Cache::expire(array(self::GROUP_NAME, $request_id));
					}
				}
			}
		}
	}

	/**
	 * Clears cache for the given post after it's updated. includes all CRUD operations.
	 *
	 * @param Post the post object to clear cache for
	 * @see StaticCache::cache_invalidate()
	 */
	public function action_post_update_after( Post $post )
	{
		$urls = array(
			$post->comment_feed_link,
			$post->permalink,
			URL::get('atom_feed', 'index=1'),
			Site::get_url('habari')
			);
		$this->cache_invalidate($urls);
	}

	/**
	 * Clears cache for the given comments parent post after it's updated. includes all
	 * CRUD operations.
	 *
	 * @param Comment the comment object to clear cache for it's parent post
	 * @see StaticCache::cache_invalidate()
	 */
	public function action_comment_update_after( Comment $comment )
	{
		if ( $comment->status == Comment::STATUS_APPROVED ) {
			$urls = array(
				$comment->post->comment_feed_link,
				$comment->post->permalink,
				URL::get('atom_feed', 'index=1'),
				Site::get_url('habari')
				);
			$this->cache_invalidate($urls);
		}
	}

	/**
	 * Setup the initial ignore list on activation. Ignores URLs matching the following:
	 * /admin, /feedback, /user, /ajax, /auth_ajax, ?nocache, /auth and /cron.
	 *
	 */
	public function action_plugin_activation()
	{
		Options::set_group( 'staticcache', array( 'ignore_list' => '/admin,/feedback,/user,/ajax,/auth_ajax,?nocache,/auth,/cron',
												  'cache_method' => 'habari',
												  'expire' => self::EXPIRE ) );
	}

	/**
	 * Remove dashboard module and empty cache when deactivating the plugin.
	 *
	 */
	public function action_plugin_deactivation()
	{
		# Clear the cache
		self::clear_staticcache();
		# Remove any other instances of this cronjob
		Crontab::delete_cronjob( 'StaticCache Garbage Collection' );
	}

	/**
	 * Adds a 'configure' action to the pllugin page.
	 *
	 * @param array $actions the default plugin actions
	 * @param strinf $plugin_id the plugins id
	 * @return array the actions to add
	 */
	public function filter_plugin_config()
	{
		$actions['configure'] = _t( 'Configure', 'staticcache' );
		if ( Options::get( 'staticcache__cache_method' ) == 'htaccess' ) {
			$actions['htaccess'] = _t( 'Rewrite Rules' );
		}
		return $actions;
	}

	/**
	 * Adds the configure UI
	 *
	 * @todo add invalidate cache button
	 * @param string $plugin_id the plugins id
	 * @param string $action the action being performed
	 */
	public function action_plugin_ui_configure()
	{
		$ui = new FormUI( 'staticcache' );
		$ui->append( 'radio', 'cache_method', 'staticcache__cache_method', _t( 'Cache Method: ' ), array( 'htaccess' => _t( 'Use mod_rewrite to serve cache files. (Recommended)' ), 'habari' => _t( 'Use PHP/Habari to serve cache files.' ) ) );
			$ui->cache_method->helptext = _t( "If you are not using Apache or do not have a writeable .htaccess file, you'll need to manually update your rewrite rules with rules similar to these (change for other web servers)." );
		$ui->append( 'textarea', 'ignore', 'staticcache__ignore_list', _t( 'Do not cache any URI\'s matching these keywords (comma seperated): ', 'staticcache' ) );
			$ui->ignore->add_validator( 'validate_required' );
		$ui->append( 'text', 'expire', 'staticcache__expire', _t( 'Cache expiry (in seconds): ', 'staticcache' ) );
			$ui->expire->add_validator( 'validate_required' );
		# TODO: Give the option for a custom interval
		$ui->append( 'select', 'garbage_collect_int', 'staticcache__garbage_collect_int', _t( 'Garbage Collection Interval:' ),
			array(
				'never'		=> _t( 'Never' ),
				'hourly'	=> _t( 'Hourly' ),
				'daily' 	=> _t( 'Daily' ),
				'weekly' 	=> _t( 'Weekly' ),
				'monthly' 	=> _t( 'Monthly' ) ) );
		$ui->garbage_collect_int->helptext = _t( 'This sets the frequency stale cached entries are cleaned from the cache.  Expired cache entries are not removed when they expire, hence the need for garbage collection.' );

		if ( extension_loaded( 'zlib' ) ) {
			$compress = $ui->append( 'checkbox', 'compress', 'staticcache__compress', _t( 'Compress Cache To Save Space: ', 'staticcache' ) );
		}

		$ui->append( 'submit', 'save', _t( 'Save', 'staticcache' ) );
		$ui->on_success( array( $this, 'save_config_msg' ) );
		$ui->out();
	}

	public function action_plugin_ui_htaccess()
	{
		### Show calculated rewrite rules
		$staticcache_content = self::staticcache_content();
		$sc_contents = "\n" . implode( "\n", $staticcache_content ) . "\n";
		$htaccess = HABARI_PATH . DIRECTORY_SEPARATOR . '.htaccess';
		$gz_content = self::gzfile_htaccess_content();
		$gzhtaccess = "\n" . implode( "\n", $gz_content ) . "\n";

		$ui = new FormUI( 'staticcache' );
		$ui->append( 'static', 'show_pre', '<div class="formcontrol"><p>Static Cache requires rewrite rules for the mod_rewrite method of caching. <button style="float: none;" onclick="javascript:$(\'#calcd_rules\').toggle(); return false;" class="link_as_button">View mod_rewrite rules</button></p></div>' );
		$ui->append( 'static', 'style', '<style>#calcd_rules { display: none; } .rules_box { border: 1px solid #ddd; padding: 5px; margin-top: 10px; background-color: #fff; font-family: courier, monospace; overflow: auto; }</style>' );
		$ui->append( 'static', 'pre', '<div class="formcontrol">
			<div id="calcd_rules">
				<h3>' . $htaccess . '</h3>
				<pre class="rules_box">' . $sc_contents . '</pre>
				<br /><h3>' . HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/.htaccess</h3>
				<pre class="rules_box">' . htmlentities( $gzhtaccess ) . '</pre>
			</div></div>' );
		$ui->append( 'static', 'append', '<div class="formcontrol"><button style="float: none;" class="link_as_button">Update mod_rewrite rules</button></div>' );

		### Compare to calculated rules


		### 
		#$ui->append( 'submit', 'save', _t( 'Save', 'staticcache' ) );
		#$ui->on_success( array( $this, 'update_htaccess' ) );
		$ui->out();
	}

    public static function save_config_msg( $ui )
	{
		$ui->save();
		# If mod_rewrite is selected when saving options, create the cache dir
		# if it doesn't already exist.
		$cache_path = HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/' . $_SERVER['SERVER_NAME'] . '/';
		if ( $ui->controls['cache_method']->value == 'htaccess' ) {
			# Create our cache dir
			if ( ! file_exists( $cache_path ) ) {
				mkdir( $cache_path, 0755, true );
			}
			# Update the .htaccess file
			# TODO: Make sure it is writeable else display message.
			self::write_htaccess( self::staticcache_content() );

			# Write the cache .htaccess so our gz files can be accessed directly too
			if ( $ui->controls['compress']->value == 1 ) {
				$cache_path = HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/';
				if ( ! file_exists( $cache_path . '.htaccess' ) ) {
					$rules = self::gzfile_htaccess_content();
					# create our file as it doesn't exist
					touch( $cache_path . '.htaccess' );
					self::write_htaccess( $rules, $cache_path . '.htaccess' );
				}
			}
		}

		# Remove any other instances of this cronjob
		Crontab::delete_cronjob( 'StaticCache Garbage Collection' );
		# Add our garbage collection cronjob
		if ( $ui->controls['garbage_collect_int'] != 'never' ) {
			call_user_func_array( array( 'Crontab', 'add_' . $ui->controls['garbage_collect_int'] . '_cron' ), array( 'StaticCache Garbage Collection', array( 'StaticCache', 'garbage_collection' ), 'Clean up stale cache entries.' ) ); 
		}
				
		Session::notice( _t( 'Options saved' ) );
		return false;
	}

	/**
	 * Write our rewrite rules to .htaccess if they don't exist
	 *
	 */
	public static function update_htaccess()
	{

	}

	/**
	 * Adds the plugin to the update check routine.
	 */
	public function action_update_check()
	{
		Update::add( 'StaticCache', '340fb135-e1a1-4351-a81c-dac2f1795169',  self::VERSION );
	}

	/**
	 * gets a unique id for the current query string requested.
	 *
	 * @return string Query ID
	 */
	public static function get_query_id()
	{
		return crc32(parse_url(Site::get_url('host') . $_SERVER['REQUEST_URI'], PHP_URL_QUERY));
	}

	/**
	 * Gets a unique id for the given request URL and user id.
	 *
	 * @param int the users id. Defaults to current users id or 0 for anonymous
	 * @param string The URL. Defaults to the current REQUEST_URI
	 * @return string Request ID
	 */
	public static function get_request_id( $user_id = null, $url = null )
	{
		if ( ! $user_id ) {
			$user = User::identify();
			$user_id = $user instanceof User ? $user->id : 0;
		}
		if ( ! $url ) {
			$url = Site::get_url('host') . rtrim(parse_url(Site::get_url('host') . $_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
		}
		return crc32($user_id . $url);
	}

	/**
	 * The output buffer callback used to capture the output and cache it.
	 *
	 * @see StaticCache::init()
	 * @param string $buffer The output buffer contents
	 * @return string $buffer unchanged
	 */
	public static function store_final_output( $buffer )
	{
		// prevent caching of 404 responses
		if ( !URL::get_matched_rule() || URL::get_matched_rule()->name == 'display_404' ) {
			return $buffer;
		}

		$buffer = ( Options::get('staticcache__cache_method') == 'htaccess' ) ? self::store_final_output_modrewrite( $buffer ) : self::store_final_output_habari( $buffer );

		return $buffer;
	}

	public static function store_final_output_habari( $buffer )
	{
		$request_id = StaticCache::get_request_id();
		$query_id = StaticCache::get_query_id();
		$expire = Options::get('staticcache__expire') ? (int) Options::get('staticcache__expire') : StaticCache::EXPIRE;

		// get cache if exists
		if ( Cache::has(array(StaticCache::GROUP_NAME, $request_id)) ) {
			$cache = Cache::get(array(StaticCache::GROUP_NAME, $request_id));
		}
		else {
			$cache = array();
		}

		// see if we want compression and store cache
		$cache[$query_id] = array(
			'headers' => headers_list(),
			'request_uri' => Site::get_url('host') . $_SERVER['REQUEST_URI']
		);
		if ( Options::get('staticcache__compress') && extension_loaded('zlib') ) {
			$cache[$query_id]['body'] = gzcompress($buffer, StaticCache::GZ_COMPRESSION);
			$cache[$query_id]['compressed'] = true;
		}
		else {
			$cache[$query_id]['body'] = $buffer;
			$cache[$query_id]['compressed'] = false;
		}
		Cache::set( array(StaticCache::GROUP_NAME, $request_id), $cache, $expire );
		return $buffer;
	}

	/**
	 * The output buffer callback used to capture the output and cache it for use
	 * with mod_rewrite.
	 *
	 * @see StaticCache::init()
	 * @param string $buffer The output buffer contents
	 * @return string $buffer unchanged
	 */
	public static function store_final_output_modrewrite( $buffer )
	{
		# Don't cache if we're logged in - safest way to ensure admin data isn't cached.
		if ( User::identify()->loggedin ) {
			$buffer .= '<!-- Uncached -->';
			return $buffer;
		}

		# Create the post directory if it doesn't exist
		$cache_path = HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		if ( ! file_exists( $cache_path ) ) {
			mkdir( $cache_path, 0755, true );
		}

		$cache_buffer = $buffer . '<!-- Cached page generated by StaticCache on ' . date( "Y-m-d H:i:s", time() ) . ' -->';

		$filename = ( strpos( $cache_path, '/atom/' ) ) ? 'index.xml' : 'index.html';

		# Write out content to index.html
		file_put_contents( $cache_path . '/' . $filename, $cache_buffer, LOCK_EX);

		# Save a compressed copy too
		if ( Options::get( 'staticcache__compress' ) && extension_loaded( 'zlib' ) ) {
			$cache_buffer .= "\n<!-- Compression: gzip -->";
			$gz_data = gzencode( $cache_buffer, StaticCache::GZ_COMPRESSION );
			file_put_contents( $cache_path . '/' . $filename . '.gz', $gz_data, LOCK_EX );
		}
		return $buffer;
	}

	/**
	 * This function updates the main Habari .htaccess file and also creates
	 * the .htaccess file in the cache directory.
	 *
	 * @param array $rules An array of rules you wish to add to the beginning of the htaccess file. Each element represents a new rule.
	 * @param string $file Path to the .htaccess file to update. Default is the HABARI_PATH/.htaccess.
	 *
	 */
	public static function write_htaccess( $rules = array(), $file = null )
	{
		if ( false === strpos( $_SERVER['SERVER_SOFTWARE'], 'Apache' ) ) {
			// .htaccess is only needed on Apache
			// @TODO: Notify people on other servers to take measures to secure the SQLite file.
			return true;
		}

		$htaccess = ( is_null( $file ) ) ? HABARI_PATH . DIRECTORY_SEPARATOR . '.htaccess' : $file;
		if ( !file_exists( $htaccess ) ) {
			// no .htaccess to write to
			return false;
		}
		if ( !is_writable( $htaccess ) ) {
			// we can't update the file
			return false;
		}

		// Get the files clause
		#$staticcache_content = self::staticcache_content();
		#$sc_contents = "\n" . implode( "\n", $staticcache_content ) . "\n";
		$rules = "\n" . implode( "\n", $rules ) . "\n";

		// See if it already exists
		$current_file_contents = file_get_contents( $htaccess );
		if ( false === strpos( $current_file_contents, $rules ) ) {
			// If not, add the rule to the beginning of the .htaccess file
			if ( false === file_put_contents( $htaccess, $rules . $current_file_contents ) ) {
				// Can't write to the file
				return false;
			}
	
		}
		// Success!
		return true;
	}

	/**
	 * Rewrite rules that will be appended to the htaccess file
	 *
	 */
	private static function staticcache_content()
	{
		$rewrite_base = trim( dirname( $_SERVER['SCRIPT_NAME'] ), '/\\' );
		$contents = array(
			'### STATICCACHE START',
			'RewriteEngine On',
			'RewriteBase /' . $rewrite_base,
			'RewriteCond %{REQUEST_METHOD} !POST',
			'RewriteCond %{QUERY_STRING} !.*=.*',
			'RewriteCond %{HTTP:Cookie} !^.*staticcache_(logged_in|commenter).*$',
			'RewriteCond %{HTTP:Accept-Encoding} gzip',
			'RewriteCond ' . Site::get_dir( 'config' ) . '/user/cache/staticcache/%{SERVER_NAME}' . Site::get_path( 'habari' ) . '/$1/index.html.gz -f',
			'RewriteRule ^(.*) "' . Site::get_dir( 'config' ) . '/user/cache/staticcache/%{SERVER_NAME}' . Site::get_path( 'habari' ) . '/$1/index.html.gz" [L]',
			'',
			'RewriteCond %{REQUEST_METHOD} !POST',
			'RewriteCond %{QUERY_STRING} !.*=.*',
			'RewriteCond %{HTTP:Cookie} !^.*staticcache_(logged_in|commenter).*$',
			'RewriteCond ' . Site::get_dir( 'config' ) . '/user/cache/staticcache/%{SERVER_NAME}' . Site::get_path( 'habari' ) . '/$1/index.html -f',
			'RewriteRule ^(.*) "' . Site::get_dir( 'config' ) . '/user/cache/staticcache/%{SERVER_NAME}' . Site::get_path( 'habari' ) . '/$1/index.html" [L]',
			'### STATICCACHE END'
		);

		return $contents;
	}

	private static function gzfile_htaccess_content()
	{
		$rules = array(
					'# BEGIN STATICCACHE',
					'<IfModule mod_mime.c>',
					'  <FilesMatch "\.html\.gz$">',
					'    ForceType text/html',
					'    FileETag None',
					'  </FilesMatch>',
					'  AddEncoding gzip .gz',
					'  AddType text/html .gz',
					'</IfModule>',
					'<IfModule mod_deflate.c>',
					'  SetEnvIfNoCase Request_URI \.gz$ no-gzip',
					'</IfModule>',
					'<IfModule mod_headers.c>',
					'  Header set Vary "Accept-Encoding, Cookie"',
					'  Header set Cache-Control "max-age=' . Options::get( 'staticcache__expire' ) . ', must-revalidate"',
					'</IfModule>',
					'<IfModule mod_expires.c>',
					'  ExpiresActive On',
					'  ExpiresByType text/html "modification plus ' . Options::get( 'staticcache__expire' ) . ' seconds"',
					'</IfModule>',
					'# END STATICCACHE'
				);
		return $rules;
	}


	/**
     * Set a login cookie.
     *
     * We do this so we can easily determine if we're logged in or not as we
     * can't access the PHPSESSION vars.
     * 
     */
    public function filter_user_authenticate( $user )
    {
        setcookie( 'staticcache_logged_in', true, time() + HabariDateTime::HOUR, Site::get_path( 'base', true ) );
        return $user;
    }
    /**
	 * Unset my login cookie
	 */
    public function action_user_logout()
    {
        setcookie( 'staticcache_logged_in', null, -1, Site::get_path( 'base', true ) );
    }

    /**
     * Set a 24 hour cookie when a user comments so they get
     * non-cached data whilst waiting for their comment to be
     * approved
     */
    public function action_comment_accepted( $comment )
    {
    	if ( $comment->status == Comment::STATUS_UNAPPROVED ) {
    		setcookie( 'staticcache_commenter', true, time() + HabariDateTime::DAY, Site::get_path( 'base', true ) );
    	}
    }

    /**
     * Clear cache when changing themes
     *
     */
    public function action_theme_activated_any()
    {
    	self::clear_staticcache();
    }


    /**
     * Perform the garbage collection of mod_rewrite cache files
     *
     * This goes through the cache and removes all entries that have expired.
     * An entry is considered expired when the configured number of seconds 
     * has passed since the file was last modified.
     *
     * Thoughts: If you get to the point where every page has been cached to file,
     * your Habari cronjobs, including the one that runs this, won't run.  
     * 
     * Need to come up with a reliable way of ensuring the cronjobs continue...
     * 	- maybe don't cache the atom feed.  That might be enough to kick off cronjobs.
     *
     */
    public static function garbage_collection()
    {
    	# Walk this site's cache files and check if expired.
    	$cache_dir = HABARI_PATH . '/user/cache/' . self::GROUP_NAME . '/' . $_SERVER['SERVER_NAME'] . Site::get_path( 'habari' );
    	self::rrmdir( $cache_dir, Options::get( 'staticcache__expire' ) );
    	return true;
    }

    private static function rrmdir( $dir, $expire = 0 )
    {
		if ( is_dir( $dir ) ) {
			$objects = scandir( $dir );
			foreach ( $objects as $object ) {
				if ( $object != "." && $object != "..") {
					if ( filetype( $dir . "/" . $object ) == "dir" ) {
						self::rrmdir( $dir . "/" . $object ); 
					}
					else {
						if ( time() - filemtime( $dir . "/" . $object ) >= $expire ) {
							unlink( $dir . "/" . $object );
						}
					}
				}
			}
			reset( $objects );
			@rmdir( $dir );
		}
	}
}



?>
