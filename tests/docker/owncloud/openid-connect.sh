#!/usr/bin/env bash

echo "checking openid .well-known/openid-configuration configuration"
if ! grep -q openid-configuration .htaccess; then
    a2enmod proxy proxy_http
    echo "enabled mod proxy for apache"
    sed -i '/well-known\/caldav \/remote.php\/dav\/ \[R=301,L\]/a RewriteRule ^\.well-known/openid-configuration http://localhost:8080/index.php/apps/openidconnect/config [P]' .htaccess
    echo "added .well-known/openid-configuration"
else
    echo ".well-known/openid-configuration already exists"
fi

true
