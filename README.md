# jeedom-push-forward

This project is made to receive Push requests made by Jeedom and forward them to an external API.

It's and must be "agnostic". It receive values from a source (Jeedom) forward them in GET or POST to another API.

Jeedom centralize every modules dedicated to the domotic part.
It provide a way to `push` it's events to an URL.

In order to not lose requests during a network cut or when the external API is broken, the script store the request and
send them again when the API answer is ok.

This script was initially developed for `Jeedom v4.0.25` with `PHP 7.3.9`.

## Jeedom

First you have to set a "global" push url.
This URL must be "internal" to avoid network issues and should point to the `www/forward.php` script.

To get an "internal" URL we need to setup a new `apache2` vhost.

This is an example used on a Jeedom v4.

```
Listen 8080

<Directory /home/www-data/jeedom-push-forward/www>
	Options Indexes FollowSymLinks
	AllowOverride None
	Require all granted
</Directory>

<VirtualHost *:8080>
        DocumentRoot /home/www-data/jeedom-push-forward/www
        ErrorLog /home/www-data/jeedom-push-forward/www/logs/http.error
</VirtualHost>

# vim: syntax=apache ts=4 sw=4 sts=4 sr noet
```

The push URL will be something like `http://localhost:8080/forward.php?...`

More informations on the Push URL : https://jeedom.github.io/core/fr_FR/administration#tocAnchor-1-9-2

## Parameters

Use must update the `forward.php` file to complete the `[PUT_YOUR_API_URL_HERE]` and if the script must call it in `GET` or `POST`.

```
// Put here the URL of the API on which this script must forward the Jeedom call.
define('API_URL', '[PUT_YOUR_API_URL_HERE]');

// Put here the HTTP verb used to forward the call
// Must be POST or GET
define('API_METHOD', 'POST');
```

# Last info

I made this script for an internal project. It was not designed to be shared so some part of the code could seems useless here.
I have some improvment idea but not a lot of time to work on them. Feel free to ask if you want some other things.