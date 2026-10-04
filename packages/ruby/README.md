# Barelytics for Ruby

Ruby 3.3+ library with a local SQLite store and optional Rack middleware. Rails can use the same middleware; Rails is not a dependency.

```ruby
require 'barelytics'
store = Barelytics::Store.new(data_directory: '/srv/my-app/private/barelytics')
store.track_page_view(path: '/articles/example', user_agent: request.user_agent)
store.dashboard(period_days: 30)
store.audit
```

For Rack, wrap the app with `Barelytics::Rack.new(app, store: store)` and exclude API, health, and background routes at the application layer. Use either middleware or an explicit call on a given response, never both. For Rails, configure the middleware in `config/application.rb` and keep the SQLite directory on persistent, non-public storage. Strict Mode only stores daily normalized path totals; dimensions require explicit acknowledged opt-in. See [framework recipes](../../docs/integrations/README.md) and the shared contract in `../../spec/`.

Run tests with `ruby -Ilib test/test_barelytics.rb` after installing the gem dependencies with `bundle install`.
