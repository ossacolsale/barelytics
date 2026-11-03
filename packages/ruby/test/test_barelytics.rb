# frozen_string_literal: true

require 'minitest/autorun'
require 'json'
require 'tmpdir'
require 'stringio'
require_relative '../lib/barelytics'

class BarelyticsTest < Minitest::Test
  def test_shared_path_vectors
    vectors = JSON.parse(File.read(File.expand_path('../../../spec/test-vectors/path-normalization.json', __dir__)))
    vectors.each do |vector|
      input = vector['input']
      if input.is_a?(Hash)
        assert_nil Barelytics.normalize_path(input['prefix'] + (input['repeat'] * input['count']))
      elsif vector['expected'].nil?
        assert_nil Barelytics.normalize_path(input), input
      else
        assert_equal vector['expected'], Barelytics.normalize_path(input), input
      end
    end
  end

  def test_shared_bot_vectors
    vectors = JSON.parse(File.read(File.expand_path('../../../spec/test-vectors/bot-filtering.json', __dir__)))
    vectors.each do |vector|
      assert_equal vector['bot'], Barelytics.bot?(vector['user_agent'], vector['additional_patterns'] || []), vector['user_agent']
    end
  end

  def test_aggregates_privacy_dimensions_audit_retention_and_migrations
    Dir.mktmpdir do |dir|
      store = Barelytics::Store.new(data_directory: dir)
      assert_equal 'strict', store.configuration[:profile]
      assert_equal false, store.configuration[:country_collection]
      assert_equal 'PASS', store.audit[:result]
      assert store.track_page_view(path: '/article', user_agent: 'Mozilla/5.0 Chrome/120 Windows')
      refute store.track_page_view(path: '/private/record')
      refute store.track_page_view(path: '/article', user_agent: 'Googlebot')
      assert_equal 1, store.dashboard[:total]
      assert_equal 1, store.admin_data('dashboard')['active_pages']
      assert_equal '/article', store.admin_data('pages')['rows'].first['path']
      refute store.admin_data('dimensions', 'dimension' => 'country')['enabled']
      assert_raises(ArgumentError) { store.update_privacy({ country_collection: true }) }
      store.update_privacy({ country_collection: true, referrer_collection: true, browser_collection: true, device_collection: true, os_collection: true }, acknowledged: true)
      assert_equal 'extended', store.configuration[:profile]
      assert store.track_page_view(path: '/extended', user_agent: 'Mozilla/5.0 Chrome/120 Windows', country: 'it', referrer: 'https://example.test/path')
      dimension_db = SQLite3::Database.new(store.database_path)
      assert_equal 3, dimension_db.get_first_value("SELECT COUNT(*) FROM dimensions_daily WHERE path='/extended'")
      assert_equal 1, dimension_db.get_first_value("SELECT COUNT(*) FROM referrers_daily WHERE path='/extended'")
      assert_equal 'IT', dimension_db.get_first_value("SELECT country FROM pageviews_daily WHERE path='/extended'")
      dimension_db.close
      assert_equal 'strict', store.return_to_strict_mode
      assert_equal 'PASS', store.audit[:result]
      store.set_retention(30)
      raw = SQLite3::Database.new(store.database_path)
      raw.execute('INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,?)', ['2000-01-01', '/expired', 'XX', 4])
      assert store.cleanup
      assert_equal 0, raw.get_first_value('SELECT COUNT(*) FROM pageviews_daily WHERE path=?', ['/expired'])
      assert_equal 3, raw.get_first_value('SELECT COUNT(*) FROM schema_migrations')
      raw.close
      store.close
      second = Barelytics::Store.new(data_directory: dir)
      assert_equal 'strict', second.configuration[:profile]
      assert_equal 'PASS', second.audit[:result]
      second.close
    end
  end

  def test_rack_admin_shares_ui_and_applies_host_auth_and_csrf_callbacks
    Dir.mktmpdir do |dir|
      store = Barelytics::Store.new(data_directory: dir)
      store.track_page_view(path: '/article')
      app = Barelytics::RackAdmin.new(->(_env) { [404, {}, []] }, store: store,
        authorize: ->(env) { env['HTTP_X_ADMIN'] == 'yes' },
        verify_csrf: ->(_env, token) { token == 'valid-token' },
        csrf_token: ->(_env) { 'valid-token' })
      status, headers, body = rack_request(app, 'GET', '/barelytics/admin/api/dashboard', extra: { 'HTTP_X_ADMIN' => 'yes' })
      assert_equal 200, status
      assert_equal 1, JSON.parse(body.join)['data']['active_pages']
      assert_equal 'nosniff', headers['X-Content-Type-Options']
      status, _headers, body = rack_request(app, 'GET', '/barelytics/admin/', extra: { 'HTTP_X_ADMIN' => 'yes' })
      assert_equal 200, status
      assert_includes body.join, 'Analytics administration'
      status, headers, body = rack_request(app, 'GET', '/barelytics/admin/admin-ui/brand-mark.png', extra: { 'HTTP_X_ADMIN' => 'yes' })
      assert_equal 200, status
      assert_equal 'image/png', headers['Content-Type']
      assert body.join.start_with?("\x89PNG\r\n\x1a\n".b)
      status, = rack_request(app, 'GET', '/barelytics/admin/api/session')
      assert_equal 403, status
      status, = rack_request(app, 'POST', '/barelytics/admin/api/strict', body: { csrf: 'wrong' }.to_json, extra: { 'HTTP_X_ADMIN' => 'yes' })
      assert_equal 403, status
      store.close
    end
  end

  def test_automation_vectors_and_lifecycle
    vectors = JSON.parse(File.read(File.expand_path('../../../spec/test-vectors/automation.json', __dir__)))
    vectors.each do |v|
      actual = Barelytics.classify_user_agent(v['user_agent'], v.fetch('additional_patterns', []))
      %w[category family identity automation].each { |key| assert_equal v[key], actual[key.to_sym] }
      refute actual[:verified]
    end
    Dir.mktmpdir do |dir|
      store = Barelytics::Store.new(data_directory: dir)
      refute store.track_page_view(path: '/article', user_agent: 'GPTBot/1')
      refute store.track_page_view(path: '/private/report', user_agent: 'GPTBot/1')
      assert_equal 1, store.admin_data('automation')['total']
      assert_equal 0, store.dashboard[:total]
      raw = SQLite3::Database.new(store.database_path)
      raw.execute("INSERT INTO automation_daily VALUES ('2000-01-01','/old','ai','openai',2)")
      raw.execute("UPDATE settings SET value='invalid-cleanup-fixture' WHERE key='last_cleanup_at'")
      assert store.track_page_view(path: '/fresh')
      assert_equal 0, raw.get_first_value("SELECT COUNT(*) FROM automation_daily WHERE day='2000-01-01'")
      store.update_privacy({ browser_collection: true }, acknowledged: true)
      raw.execute("CREATE TRIGGER fail_dimension BEFORE INSERT ON dimensions_daily BEGIN SELECT RAISE(ABORT, 'fixture'); END")
      refute store.track_page_view(path: '/interrupted')
      assert_equal 0, raw.get_first_value("SELECT COUNT(*) FROM pageviews_daily WHERE path='/interrupted'")
      raw.execute('DROP TRIGGER fail_dimension')
      store.delete_all
      assert_equal 0, store.admin_data('automation')['total']
      raw.close
      store.close
    end
  end

  def test_shared_browser_protocol_and_page_dimensions
    Dir.mktmpdir do |dir|
      store = Barelytics::Store.new(data_directory: dir)
      app = Barelytics::RackTrack.new(->(_env) { [404, {}, []] }, store: store)
      vectors = JSON.parse(File.read(File.expand_path('../../../spec/test-vectors/protocol.json', __dir__)))
      vectors.each_with_index do |v, index|
        store.update_privacy({ referrer_collection: v['referrer_collection'] == true }, acknowledged: true)
        raw = if v['raw_hex'] then [v['raw_hex']].pack('H*') elsif v['body_bytes'] then 'x' * v['body_bytes'] elsif v['body'].is_a?(String) then v['body'] else JSON.generate(v.fetch('body', {})) end
        raw = raw.ljust(v['body_size']) if v['body_size']
        status, headers, = rack_request(app, v['method'], '/barelytics/track', body: raw, extra: { 'CONTENT_TYPE' => v.fetch('content_type', 'application/json'), 'HTTP_REFERER' => 'https://beacon.test/current?private' })
        assert_equal v['status'], status, "vector #{index}"
        assert_equal 'no-store', headers['Cache-Control']
        refute headers.key?('Set-Cookie')
      end
      store.update_privacy(Barelytics::DIMENSIONS.to_h { |key| [key, true] }, acknowledged: true)
      store.track_page_view(path: '/one', user_agent: 'Mozilla/5.0 Firefox/1 Windows', country: 'it', referrer: 'https://one.test/private?q=secret')
      store.track_page_view(path: '/two', user_agent: 'Mozilla/5.0 Chrome/1 Android Mobile', country: 'us', referrer: 'https://two.test/private?q=secret')
      { 'country' => 'IT', 'referrer' => 'one.test', 'browser' => 'Firefox', 'device' => 'Desktop', 'os' => 'Windows' }.each do |dimension, expected|
        assert_equal [{ 'value' => expected, 'views' => 1 }], store.admin_data('dimensions', 'dimension' => dimension, 'path' => '/one')['rows']
        assert_raises(ArgumentError) { store.admin_data('dimensions', 'dimension' => dimension, 'path' => 'https://bad.test') }
      end
      store.return_to_strict_mode
      assert_equal %w[country referrer browser device os], store.audit[:historical_dimensions]
      store.close
    end
  end

  def test_legacy_migration_schema_audit_and_future_guard
    Dir.mktmpdir do |dir|
      raw = SQLite3::Database.new(File.join(dir, 'analytics.sqlite'))
      raw.execute_batch(File.read(File.expand_path('../../../spec/test-vectors/legacy-v2.sql', __dir__)))
      store = Barelytics::Store.new(data_directory: dir)
      assert_equal 30, store.configuration[:retention_days]
      assert_equal 7, raw.get_first_value("SELECT views FROM pageviews_daily WHERE path='/legacy'")
      assert_equal 'fixture-unchanged-credential', raw.get_first_value("SELECT value FROM settings WHERE key='admin_password_hash'")
      assert_equal 3, raw.get_first_value('SELECT COUNT(*) FROM schema_migrations')
      raw.execute('CREATE TABLE request_log(user_agent TEXT)')
      assert_equal 'FAIL', store.audit[:result]
      raw.execute('DROP TABLE request_log')
      assert_equal 'PASS', store.audit[:result]
      raw.execute("INSERT INTO schema_migrations VALUES (99,'fixture')")
      assert_raises(ArgumentError) { Barelytics::Store.new(data_directory: dir) }
      assert_equal 7, raw.get_first_value("SELECT views FROM pageviews_daily WHERE path='/legacy'")
      store.close
      raw.close
    end
  end

  private

  def rack_request(app, method, path, body: '', extra: {})
    require 'uri'
    uri = URI(path)
    env = { 'REQUEST_METHOD' => method, 'PATH_INFO' => uri.path, 'QUERY_STRING' => uri.query.to_s,
      'rack.input' => StringIO.new(body), 'CONTENT_LENGTH' => body.bytesize.to_s }.merge(extra)
    status, headers, response = app.call(env)
    [status, headers, response]
  end
end
