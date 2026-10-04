# Barelytics for Ruby

Ruby 3.3+ library with a local SQLite store and optional Rack middleware. Rails can use the same middleware; Rails is not a dependency.

```ruby
require 'barelytics'
store = Barelytics::Store.new(data_directory: '/srv/my-app/private/barelytics')
store.track_page_view(path: '/articles/example', user_agent: request.user_agent)
store.dashboard(period_days: 30)
store.audit
```

For Rack, wrap the app with `Barelytics::Rack.new(app, store: store)` and exclude API, health, and background routes at the application layer. Mount `Barelytics::RackAdmin` at an authenticated admin path with host `authorize`, `verify_csrf`, and `csrf_token` callbacks. It serves the shared zero-build UI and API for overview, pages, day × page, page timelines, dimensions, privacy settings, cleanup, delete-all, system status, and audit. Rails can use its existing authentication and CSRF helpers in those callbacks; Barelytics does not create a user store. Keep SQLite on persistent, non-public storage. Strict Mode only stores daily normalized path totals; dimensions require explicit acknowledged opt-in. See [framework recipes](../../docs/integrations/README.md) and the shared contract in `../../spec/`.

Run tests with `ruby -Ilib test/test_barelytics.rb` after installing the gem dependencies with `bundle install`.
