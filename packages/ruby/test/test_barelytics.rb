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
      assert_equal 2, raw.get_first_value('SELECT COUNT(*) FROM schema_migrations')
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
