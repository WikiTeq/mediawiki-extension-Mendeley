-- Mendeley OAuth tokens (singleton row per wiki)
CREATE TABLE /*_*/mendeley_oauth_tokens (
  moa_id INT unsigned NOT NULL PRIMARY KEY DEFAULT 1,
  moa_access_token BLOB NOT NULL,
  moa_refresh_token BLOB NOT NULL,
  moa_valid TINYINT unsigned NOT NULL DEFAULT 1,
  moa_updated INT unsigned NOT NULL DEFAULT 0
) /*$wgDBTableOptions*/;
