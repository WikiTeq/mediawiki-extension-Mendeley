# Mendeley

A MediaWiki Extension to work with the Mendeley API

# Setup 

* Create new application at https://dev.mendeley.com/myapps.html
* Set `$wgMendeleyConsumerKey` to app `ID` and `$wgMendeleyConsumerSecret` to app `SECRET` values
* On **Special:MendeleyAuth** you will see the redirect URL to use in your Mendeley app settings (it is built automatically from your wiki URL)

That's all!

# Setup for private spaces

To fetch data from private groups and resources on Mendeley you need OAuth tokens. The preferred way is to obtain them via the extension:

* Go to **Special:MendeleyAuth** and use "Connect with Mendeley" to complete the OAuth flow. Tokens are stored in the database (table `mendeley_oauth_tokens`). When a cache (Memcached or Redis) is available, the access token is cached for about an hour to reduce database reads.
* On Special:MendeleyAuth you can **Check** (test the stored token), **Refresh** (get a new access token), or **Delete** (remove the stored tokens from the database). If the API returns 401 or refresh fails, tokens are only marked invalid (`moa_valid = 0`); they are not deleted. Only the "Delete" button removes the row from the database.

**Fallback:** You can still set `$wgMendeleyToken` and `$wgMendeleyRefreshToken` in LocalSettings.php (e.g. from another source). Token resolution order is: cache → config → database (including invalid rows; on successful use they are marked valid again) → client credentials.

**Important (when using config tokens):** Memcached or Redis is required if you use `$wgMendeleyToken` / `$wgMendeleyRefreshToken`. Ensure one of these is configured as main cache type, e.g.:

```
$wgMemCachedServers = [ '127.0.0.1:11211' ];
$wgMainCacheType = CACHE_MEMCACHED;
```

You can also use `maintenance/refreshToken.php` to force a token refresh when using config-based tokens.

For troubleshooting, enable the Mendeley debug log in LocalSettings.php:

```php
$wgDebugLogGroups['Mendeley'] = '/var/log/mediawiki/mendeley.log';
```

# Usage

Use `mendeley` parser function to fetch Mendeley data:
```
{{#mendeley:doi=10.1103/PhysRevA.20.1521|parameter=title}}
```

Or `Special:MendeleyImport` special page to import groups of documents into your wiki

# Import configuration

By default, the extension will import documents as `Article` template with no fields and
name imported pages after the documents IDs on the Mendeley DB. This can be altered for your needs:

* `$wgMendeleyTemplate` - name of the template to use for imported pages
* `$wgMendeleyTemplateFields` - mapping scheme between template and Mendeley fields, see Mendeley fields names at
https://api.mendeley.com/apidocs/docs#!/documents/getDocuments, the format is `mendeley_field => template_field`
* `$wgMendeleyPageFormula` - formula for imported pages titles, mendeley fields tokens will be substituted,
eg: `Reference:<title>` will put imported page into a `Reference` namespace with a `title` field as page name

Example of fields mapping:
```
$wgMendeleyTemplateFields = [
	'type' => 'Type',
	'title' => 'Title',
	'abstract' => 'Abstract',
	'accessed' => 'Accessed',
	'authors' => '@Authors',
	'source' => 'Source',
	'volume' => 'Volume',
	'websites' => '@Websites',
	'identifiers/doi' => 'Doi',
	'keywords' => 'Keywords',
];
```

Not that some fields on the Mendeley are lists, use `@` char in front of template field name to split field values,
list delimiter can be configured via `$wgMendeleyTemplateFieldsMapDelimiter`, it's `;` by default.

You can also import groups via `maintenance/importGroup.php` script:

```
php maintenance/importGroup.php --group_id XXX
```

## Testing

### PHPUnit

The extension includes PHPUnit integration tests. From the MediaWiki root (with Mendeley enabled):

```bash
php tests/phpunit/phpunit.php extensions/Mendeley/tests/phpunit/
```

### Phan (static analysis)

Phan is configured for the extension. From the extension directory:

```bash
cd extensions/Mendeley
composer install
MW_INSTALL_PATH=/path/to/mediawiki vendor/bin/phan -d . --long-progress-bar
```

**Note:** Phan 3.2.x (used by mediawiki-phan-config 0.10.6) has compatibility issues with PHP 8.2. Use PHP 7.4 or 8.0 for running Phan.

Please see more at https://www.mediawiki.org/wiki/Extension:Mendeley
