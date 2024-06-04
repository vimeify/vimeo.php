# Interacting with the Vimeo API using PHP

This is a simple PHP library for interacting with the [Vimeo API](https://developers.vimeo.com).

## Before you start

### Get started with the Vimeo API

If you’re new to Vimeo APIs, check out [Getting Started: The Basics](https://developer.vimeo.com/api/start) before diving into the content on this page. 

### Understand the PHP hierarchy

The API docs often use dot notation to represent a hierarchy of data, such as `privacy.view`. Since the PHP library sends all data using JSON, you must use nested associative arrays instead of dot notation.

```php
// The documentation refers to the following as `privacy.view`
$params = ['privacy' => ['view' => 'disable']];
```

## Install and access the PHP library

To install the PHP library, run the following command:

```bash
composer require vimeify/vimeo-api
```

After installation is complete, you can access the library by using `$lib = new \Vimeo\Vimeo($client_id, $client_secret)` in a Composer-enabled PHP script.

## Advanced examples

To see examples of the most common use cases of the PHP library, visit our [PHP Library Examples](https://developer.vimeo.com/api/libraries/examples) page. 

## Why this library

This library was built for the purposes of the Vimeify.com WordPress plugin, however, anyone can use it, really!

The main goal of this library is to maintain backwards compatibility and stability for the modern PHP

We aim to support at least four major PHP versions. This way the library will be compatible with most WordPress installs.

## Support

To troubleshoot an issue, reach out to [Vimeo Support](https://vimeo.com/help/contact).
