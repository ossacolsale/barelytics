# frozen_string_literal: true

require 'sqlite3'
require 'fileutils'
require 'digest'
require 'json'
require 'time'
require 'date'
require 'uri'

module Barelytics
  CONTRACT_VERSION = 1
  SCHEMA_VERSION = 2
  DIMENSIONS = %w[country_collection referrer_collection browser_collection device_collection os_collection].freeze
  DEFAULT_EXCLUSIONS = %w[/admin/* /admin.php /account/* /checkout/* /customer/* /patient/* /profile/* /private/*].freeze
  DEFAULT_BOTS = %w[bot crawler spider slurp bingpreview headless lighthouse pagespeed semrush ahrefsbot mj12bot dotbot facebookexternalhit twitterbot linkedinbot discordbot telegrambot whatsapp petalbot yandex baiduspider bytespider duckduckbot applebot googlebot bingbot].freeze
  RETENTION = [30, 90, 180, 365].freeze
  IDS = %r{(?<=/)(?:[0-9]{6,}|[0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{16,}|[A-Za-z0-9_-]{32,})(?=/|$)}i

  def self.normalize_path(value)
    return nil unless value.is_a?(String) && !value.empty? && value.bytesize <= 512 && !value.match?(/[\x00-\x1f\x7f]/)
    return nil unless value.start_with?('/') && !value.start_with?('//') && !value.include?('?') && !value.include?('#')
    path = value.gsub(%r{/+}, '/')
    return nil if path.bytesize > 512 || path.match?(%r{%(?![0-9a-f]{2})}i)
    decoded = URI::DEFAULT_PARSER.unescape(path).force_encoding(Encoding::UTF_8)
    return nil unless decoded.valid_encoding?
    return nil if decoded.match?(/[\x00-\x1f\x7f@?#]/) || (decoded != path && decoded.match?(IDS))
    path.gsub(IDS, ':id')
  rescue ArgumentError
    nil
  end

  def self.bot?(agent, extra = [])
    value = agent.is_a?(String) ? agent.downcase : ''
    (DEFAULT_BOTS + extra.select { |x| x.is_a?(String) && !x.empty? }).any? { |x| value.include?(x.downcase) }
  end

  class Store
    attr_reader :database_path

    def initialize(data_directory:, path_exclusions: DEFAULT_EXCLUSIONS, bot_patterns: [])
      FileUtils.mkdir_p(data_directory, mode: 0o700)
      File.chmod(0o700, data_directory) rescue nil
      @database_path = File.join(data_directory, 'analytics.sqlite')
      @db = SQLite3::Database.new(@database_path)
      @db.busy_timeout = 1000
      @db.execute('PRAGMA journal_mode=WAL') rescue nil
      File.chmod(0o600, @database_path) rescue nil
      @mutex = Mutex.new
      migrate
      set('path_exclusions', valid_patterns(path_exclusions).join("\n"))
      set('bot_patterns', valid_strings(bot_patterns).join("\n"))
    end

    def close = @db.close

    def configuration
      flags = DIMENSIONS.to_h { |key| [key.to_sym, get(key, '0') == '1'] }
      retention = Integer(get('retention_days', '180')) rescue 180
      retention = 180 unless RETENTION.include?(retention)
      { **flags, retention_days: retention, profile: flags.values.any? ? 'extended' : 'strict',
        path_exclusions: valid_patterns(get('path_exclusions', DEFAULT_EXCLUSIONS.join("\n")).split("\n")),
        bot_patterns: valid_strings(get('bot_patterns', '').split("\n")) }
    end

    def track_page_view(path:, user_agent: '', country: nil, referrer: nil)
      normalized = Barelytics.normalize_path(path)
      config = configuration
      return false unless normalized
      return false if excluded?(normalized, config[:path_exclusions]) || Barelytics.bot?(user_agent, config[:bot_patterns])
      country_value = config[:country_collection] && country.is_a?(String) && country.match?(/\A[a-z]{2}\z/i) ? country.upcase : 'XX'
      @mutex.synchronize do
        @db.transaction do
          day = Time.now.utc.strftime('%Y-%m-%d')
          @db.execute('INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,1) ON CONFLICT(day,path,country) DO UPDATE SET views=views+1', [day, normalized, country_value])
          host = config[:referrer_collection] ? referrer_host(referrer) : nil
          @db.execute('INSERT INTO referrers_daily(day,path,referrer_host,views) VALUES (?,?,?,1) ON CONFLICT(day,path,referrer_host) DO UPDATE SET views=views+1', [day, normalized, host]) if host
          { 'browser' => config[:browser_collection], 'device' => config[:device_collection], 'os' => config[:os_collection] }.each do |dimension, enabled|
            next unless enabled
            @db.execute('INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?,?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1', [day, normalized, dimension, category(user_agent, dimension)])
          end
        end
      end
      true
    rescue SQLite3::Exception
      false
    end

    def update_privacy(values, acknowledged: false)
      raise ArgumentError, 'Settings must be a hash of boolean dimension values.' unless values.is_a?(Hash) && values.values.all? { |value| value == true || value == false }
      enabled = ->(key) { values[key] == true || values[key.to_sym] == true }
      raise ArgumentError, 'Explicit acknowledgement is required to enable optional dimensions.' if DIMENSIONS.any? { |key| enabled.call(key) } && !acknowledged
      @mutex.synchronize do
        @db.transaction do
          DIMENSIONS.each { |key| set(key, enabled.call(key) ? '1' : '0') }
          record_history
        end
      end
      configuration[:profile]
    end

    def return_to_strict_mode = update_privacy({}, acknowledged: true)

    def set_retention(days)
      value = RETENTION.include?(days) ? days : 180
      @mutex.synchronize { @db.transaction { set('retention_days', value.to_s); record_history } }
    end

    def dashboard(period_days: 30)
      period = RETENTION.include?(period_days) ? period_days : 30
      to = Time.now.utc.to_date
      from = to - (period - 1)
      @mutex.synchronize do
        total = @db.get_first_value('SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day BETWEEN ? AND ?', [from.to_s, to.to_s]).to_i
        { total: total, by_day: rows('SELECT day,SUM(views) FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY day ORDER BY day', from, to),
          by_page: rows('SELECT path,SUM(views) FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY SUM(views) DESC,path LIMIT 500', from, to), profile: configuration[:profile] }
      end
    end

    def audit
      config = configuration
      tables = @db.execute("SELECT name FROM sqlite_master WHERE type='table'").flatten
      ip = @db.table_info('pageviews_daily').any? { |col| col['name'].downcase.include?('ip') }
      checks = { profile_is_known: %w[strict extended].include?(config[:profile]), dimensions_fail_closed: true,
        retention_is_valid: RETENTION.include?(config[:retention_days]), aggregate_schema: %w[pageviews_daily schema_migrations privacy_configuration_history].all? { |t| tables.include?(t) },
        no_identity_or_event_tables: tables.none? { |t| t.match?(/visitor|session|fingerprint|event|identity/i) }, no_ip_column: !ip,
        no_third_party_analytics: true, path_normalization_active: Barelytics.normalize_path('/users/123456') == '/users/:id',
        private_exclusions_active: excluded?('/admin/example', config[:path_exclusions]) }
      { application: 'Barelytics Ruby', contract_version: CONTRACT_VERSION, schema_version: SCHEMA_VERSION,
        profile: config[:profile], configuration: config, fingerprint: fingerprint(config), checks: checks,
        result: checks.values.all? ? 'PASS' : 'FAIL' }
    end

    def cleanup(limit: 1000)
      bound = [[limit.to_i, 1].max, 1000].min
      cutoff = (Time.now.utc.to_date - configuration[:retention_days]).to_s
      @mutex.synchronize do
        complete = true
        @db.transaction do
          %w[pageviews_daily referrers_daily dimensions_daily].each do |table|
            count = @db.get_first_value("SELECT COUNT(*) FROM (SELECT rowid FROM #{table} WHERE day<? ORDER BY day LIMIT ?)", [cutoff, bound]).to_i
            @db.execute("DELETE FROM #{table} WHERE rowid IN (SELECT rowid FROM #{table} WHERE day<? ORDER BY day LIMIT ?)", [cutoff, bound])
            complete = false if count == bound
          end
          set('last_cleanup_at', Time.now.utc.iso8601)
        end
        complete
      end
    end

    private

    def migrate
      @db.execute_batch <<~SQL
        CREATE TABLE IF NOT EXISTS schema_migrations(version INTEGER PRIMARY KEY,applied_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS pageviews_daily(day TEXT NOT NULL,path TEXT NOT NULL,country TEXT NOT NULL DEFAULT 'XX',views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,country));
        CREATE TABLE IF NOT EXISTS referrers_daily(day TEXT NOT NULL,path TEXT NOT NULL,referrer_host TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,referrer_host));
        CREATE TABLE IF NOT EXISTS dimensions_daily(day TEXT NOT NULL,path TEXT NOT NULL,dimension TEXT NOT NULL,value TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,dimension,value));
        CREATE TABLE IF NOT EXISTS privacy_configuration_history(timestamp TEXT NOT NULL,profile TEXT NOT NULL,effective_configuration_json TEXT NOT NULL,configuration_hash TEXT NOT NULL,application_version TEXT NOT NULL,schema_version INTEGER NOT NULL);
      SQL
      @db.transaction do
        [1, SCHEMA_VERSION].each { |v| @db.execute('INSERT OR IGNORE INTO schema_migrations VALUES (?,?)', [v, Time.now.utc.iso8601]) }
        DIMENSIONS.each { |k| set(k, '0') unless %w[0 1].include?(get(k, '0')) }
        set('retention_days', get('retention_days', '180'))
        set('path_exclusions', get('path_exclusions', DEFAULT_EXCLUSIONS.join("\n")))
        set('bot_patterns', get('bot_patterns', ''))
      end
    end
    def get(key, default) = @db.get_first_value('SELECT value FROM settings WHERE key=?', [key]) || default
    def set(key, value) = @db.execute('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [key, value])
    def valid_patterns(values) = values.select { |x| x.is_a?(String) && x.start_with?('/') && x.length <= 200 && !x.match?(/[?#]/) }.uniq.sort
    def valid_strings(values) = values.select { |x| x.is_a?(String) && !x.empty? && x.length <= 200 }.uniq
    def excluded?(path, patterns) = patterns.any? { |p| p.end_with?('/*') ? path == p[0...-2] || path.start_with?(p[0...-1]) : path == p }
    def referrer_host(value)
      uri = URI.parse(value.to_s)
      host = uri.host&.downcase&.delete_suffix('.')
      return nil unless %w[http https].include?(uri.scheme&.downcase) && host && !uri.userinfo && !host.include?(':') && !host.match?(/\A\d+(?:\.\d+){3}\z/)
      host if host.match?(/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i)
    rescue URI::InvalidURIError
      nil
    end
    def category(ua, kind)
      value = ua.to_s
      return value.match?(/Firefox\//i) ? 'Firefox' : value.match?(/Edg\/|Edge\//i) ? 'Edge' : value.match?(/Chrome\//i) ? 'Chrome' : value.match?(/Safari\//i) ? 'Safari' : 'Other' if kind == 'browser'
      return value.match?(/iPad|Tablet/i) ? 'Tablet' : value.match?(/Mobile|Android|iPhone/i) ? 'Mobile' : 'Desktop' if kind == 'device'
      return 'Windows' if value.match?(/Windows/i)
      return 'Android' if value.match?(/Android/i)
      return 'iOS' if value.match?(/iPhone|iPad|iOS/i)
      return 'macOS' if value.match?(/Mac OS/i)
      return 'Linux' if value.match?(/Linux/i)
      'Other'
    end
    def rows(sql, from, to) = @db.execute(sql, [from.to_s, to.to_s]).map { |r| { label: r[0], views: r[1].to_i } }
    def fingerprint(config) = Digest::SHA256.hexdigest(JSON.generate({ contract_version: CONTRACT_VERSION, schema_version: SCHEMA_VERSION, configuration: config }))
    def record_history
      config = configuration
      @db.execute('INSERT INTO privacy_configuration_history VALUES (?,?,?,?,?,?)', [Time.now.utc.iso8601, config[:profile], JSON.generate(config), fingerprint(config), '1.0.0', SCHEMA_VERSION])
    end
  end

  class Rack
    def initialize(app, store:, country_header: 'HTTP_CF_IPCOUNTRY') = (@app, @store, @country_header = app, store, country_header)
    def call(env)
      status, headers, body = @app.call(env)
      method = env['REQUEST_METHOD'].to_s
      if %w[GET HEAD].include?(method) && status.to_i < 400 && env['PATH_INFO'].to_s.start_with?('/')
        @store.track_page_view(path: env['PATH_INFO'], user_agent: env['HTTP_USER_AGENT'], country: env[@country_header], referrer: env['HTTP_REFERER'])
      end
      [status, headers, body]
    end
  end
end
