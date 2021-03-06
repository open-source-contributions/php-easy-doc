# Getting started

Easy-Doc is an easy way to generate a static doc from
HTML/Markdown/RST/Pug/anything documentation files.

## Install

```shell
composer require easy-doc/easy-doc --dev
```

Remove `--dev` if you need to deploy the documentation bundled with the main project or
if the project only contains documentation (the same goes for libraries you may install
in Easy-Doc).

Note than `easy-doc` requires at least PHP 7.1. To allow to use it in a project with a lower
PHP level, you can use edit your **composer.json** file and set `"easy-doc/easy-doc": "0.0.0 || ^1"`
and run:

```shell
composer update
```

So it will install the version `0.0.0` if PHP < 7.1 but it will not prevent you to install it
and use it as soon as the machine running the command use PHP >= 7.1.

## Usage

Create your website file page contents in a folder of your project `doc` by default:

Example:

**doc/index.html**

```html
<p>
    Super library make everything super.
    <a href="example.html">See the example</a>
</p>
```

**doc/example.html**

```html
<pre>
$superLibrary = new SuperLibrary();

$superThing = $superLibrary->enhance($thing);
</pre>
```

And run the `easy-doc` command:

```shell
vendor/bin/easy-doc build
```

Now you can [check out how to customize your website](customize/)
