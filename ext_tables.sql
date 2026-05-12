CREATE TABLE tx_aisuite_oauth_codes (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    code varchar(256) NOT NULL DEFAULT '',
    be_user_uid int(11) unsigned NOT NULL DEFAULT '0',
    client_id varchar(256) NOT NULL DEFAULT '',
    redirect_uri text,
    code_challenge varchar(256) DEFAULT '',
    code_challenge_method varchar(10) DEFAULT 'S256',
    scopes text,
    audience varchar(512) NOT NULL DEFAULT '',
    workspace_uid int(11) NOT NULL DEFAULT '0',
    expires_at int(11) unsigned NOT NULL DEFAULT '0',
    used tinyint(1) unsigned DEFAULT '0',

    PRIMARY KEY (uid),
    UNIQUE KEY code (code(191)),
    KEY expires_at (expires_at)
);

CREATE TABLE tx_aisuite_oauth_tokens (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    token varchar(512) NOT NULL DEFAULT '',
    refresh_token varchar(512) DEFAULT '',
    be_user_uid int(11) unsigned NOT NULL DEFAULT '0',
    client_id varchar(256) DEFAULT '',
    client_name varchar(256) DEFAULT '',
    scopes text,
    audience varchar(512) NOT NULL DEFAULT '',
    workspace_uid int(11) NOT NULL DEFAULT '0',
    created_at int(11) unsigned NOT NULL DEFAULT '0',
    expires_at int(11) unsigned NOT NULL DEFAULT '0',
    last_used_at int(11) unsigned DEFAULT '0',
    last_used_ip varchar(45) DEFAULT '',
    session_credits_used int(11) unsigned DEFAULT '0',
    deleted tinyint(1) unsigned DEFAULT '0',

    PRIMARY KEY (uid),
    KEY token (token(191)),
    KEY refresh_token (refresh_token(191)),
    KEY be_user_uid (be_user_uid),
    KEY expires_at (expires_at),
    KEY audience (audience(191)),
    KEY revoked_cleanup (deleted, created_at)
);

CREATE TABLE tx_aisuite_oauth_consents (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    be_user_uid int(11) unsigned NOT NULL DEFAULT '0',
    client_id varchar(256) NOT NULL DEFAULT '',
    scopes text,
    granted_at int(11) unsigned NOT NULL DEFAULT '0',

    PRIMARY KEY (uid),
    KEY be_user_client (be_user_uid, client_id(191))
);
