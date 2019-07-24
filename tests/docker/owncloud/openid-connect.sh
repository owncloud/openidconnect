#!/usr/bin/env bash

echo "checking openid .well-known/openid-configuration configuration"
if ! grep -q openid-configuration .htaccess; then
    echo "added .well-known/openid-configuration"
    sed -i '/#### DO NOT CHANGE ANYTHING ABOVE THIS LINE ####/a RewriteRule ^\.well-known/openid-configuration /index.php/apps/openidconnect/config [R=301,L]' .htaccess
else
    echo ".well-known/openid-configuration already exists"
fi

true