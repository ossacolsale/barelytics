Gem::Specification.new do |spec|
  spec.name = 'barelytics'
  spec.version = '1.1.0'
  spec.summary = 'Self-hosted aggregate page-view analytics'
  spec.description = 'Strict-by-default, SQLite-backed first-party analytics for Ruby applications.'
  spec.authors = ['Barelytics contributors']
  spec.license = 'MIT'
  spec.homepage = 'https://github.com/ossacolsale/barelytics'
  spec.metadata = { 'source_code_uri' => spec.homepage, 'changelog_uri' => 'https://github.com/ossacolsale/barelytics/blob/main/CHANGELOG.md' }
  spec.required_ruby_version = '>= 3.3'
  spec.files = Dir['lib/**/*.rb', 'lib/barelytics/admin-ui/*', 'README.md']
  spec.require_paths = ['lib']
  spec.add_dependency 'sqlite3', '>= 2.0', '< 3'
end
