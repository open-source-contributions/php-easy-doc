<?php

namespace EasyDoc;

use EasyDoc\Command\Build;
use SimpleCli\Options\Verbose;
use SimpleCli\SimpleCli;
use SimpleXMLElement;

class EasyDoc extends SimpleCli
{
    use Verbose;

    /**
     * @var string|null
     */
    protected $layout;

    /**
     * @var array
     */
    protected $extensions = [];

    /**
     * @param bool $verbose
     */
    public function setVerbose(bool $verbose)
    {
        $this->verbose = $verbose;
    }

    /**
     * @return bool
     */
    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    /**
     * @param string|null $layout
     */
    public function setLayout(?string $layout)
    {
        $this->layout = $layout;
    }

    /**
     * @param array $extensions
     */
    public function setExtensions(array $extensions)
    {
        $this->extensions = [];

        foreach (array_merge([
            'html',
        ], $extensions) as $extension => $transformation) {
            if (is_int($extension)) {
                $extension = $transformation;
                $transformation = 'file_get_contents';
            }

            if ($transformation === 'file_eval') {
                $transformation = [$this, 'evaluatePhpFile'];
            }

            $this->extensions[strtolower($extension)] = $transformation;
        }
    }

    /**
     * Create a directory or empty it if it exists.
     *
     * @param string $websiteDirectory
     */
    public function initializeDirectory(string $websiteDirectory): void
    {
        $this->info("Initializing $websiteDirectory");
        $this->removeDirectory($websiteDirectory);
        @mkdir($websiteDirectory, 0777, true);
    }

    /**
     * Build the website directory and create HTML files from RST sources.
     *
     * @param string   $websiteDirectory Output directory
     * @param string   $assetsDirectory  Directory with static assets
     * @param string   $sourceDirectory  Directory containing .rst files
     * @param string   $baseHref         Base of link to be used if website is deployed in a folder URI
     * @param string   $index            Optional index file to be copied as index.html after the build
     *
     * @return void
     */
    public function build(string $websiteDirectory, ?string $assetsDirectory, ?string $sourceDirectory, string $baseHref, string $index = null): void
    {
        $this->info('Copying assets from '.var_export($assetsDirectory, true));
        $assetsDirectory && @is_dir($assetsDirectory)
            ? $this->copyDirectory($assetsDirectory, $websiteDirectory)
            : $this->info('assets directory skipped as empty');

        $this->writeLine('Building website from '.var_export($sourceDirectory, true), 'light_cyan');
        $sourceDirectory && @is_dir($sourceDirectory)
            ? $this->buildWebsite($sourceDirectory, $websiteDirectory, $sourceDirectory, $baseHref)
            : $this->info('source directory skipped as empty');

        if ($index) {
            $source = $websiteDirectory.'/'.$index;
            $destination = $websiteDirectory.'/index.html';
            $this->info("Copying $source to $destination");
            @copy($source, $destination);
        }

        $this->writeLine('Build finished.');
    }

    /**
     * Display message in cyan if verbose mode is on.
     *
     * @param string $message
     */
    public function info(string $message): void
    {
        if ($this->isVerbose()) {
            $this->writeLine($message, 'cyan');
        }
    }

    /**
     * Include a PHP file and returns the output buffered.
     *
     * @param string $file
     *
     * @return string
     */
    protected function evaluatePhpFile(string $file, array $locals = []): string
    {
        extract($locals);

        ob_start();
        include $file;
        $html = ob_get_contents();
        ob_end_clean();

        return $html;
    }

    /**
     * Remove a directory and all sub-directories and files inside.
     *
     * @param string $directory
     *
     * @return void
     */
    protected function removeDirectory($directory)
    {
        if (!($dir = @opendir($directory))) {
            return;
        }

        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir($directory.'/'.$file)) {
                $this->removeDirectory($directory.'/'.$file);

                continue;
            }

            unlink($directory.'/'.$file);
        }

        closedir($dir);

        @rmdir($directory);
    }

    /**
     * Deep copy a directory with all content to another directory.
     *
     * @param string $source
     * @param string $destination
     *
     * @return void
     */
    protected function copyDirectory($source, $destination)
    {
        $dir = opendir($source);
        @mkdir($destination);

        while (false !== ($file = readdir($dir))) {
            if (substr($file, 0, 1) === '.') {
                continue;
            }

            if (is_dir($source.'/'.$file)) {
                $this->copyDirectory($source.'/'.$file, $destination.'/'.$file);

                continue;
            }

            copy($source.'/'.$file, $destination.'/'.$file);
        }

        closedir($dir);
    }

    /**
     * Create HTML files from RST sources.
     *
     * @param string   $dir
     * @param string   $websiteDirectory Output directory
     * @param string   $sourceDirectory  Directory containing .rst files
     * @param string   $baseHref         Base of link to be used if website is deployed in a folder URI
     * @param string   $base             Base path for recursion
     *
     * @return void
     */
    protected function buildWebsite($dir, $websiteDirectory, $sourceDirectory, $baseHref, $base = '')
    {
        foreach (scandir($dir) as $item) {
            if (substr($item, 0, 1) === '.') {
                continue;
            }

            if (is_dir($dir.'/'.$item)) {
                $this->buildWebsite($dir.'/'.$item, $websiteDirectory, $sourceDirectory, $baseHref, $base.'/'.$item);

                continue;
            }

            $parts = explode('.', $item);
            $extension = strtolower(end($parts));
            $transformation = count($parts) < 2 ? null : ($this->extensions[$extension] ?? null);

            if (!$transformation) {
                continue;
            }

            $directory = $websiteDirectory.$base;

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $content = $transformation($dir.'/'.$item);
            $uri = $base.'/'.substr($item, 0, -strlen($extension) - 1).'.html';

            $menu = $this->buildMenu($uri, $sourceDirectory, $baseHref);
            $layout = file_exists($this->layout) ? $this->layout : __DIR__.'/defaultLayout.php';

            file_put_contents($websiteDirectory.$uri, $this->evaluatePhpFile($layout, [
                'content' => $content,
                'menu' => $menu,
                'uri' => $uri,
                'baseHref' => $baseHref,
            ]));
        }
    }

    /**
     * Check if the node is index (that is skipped in the building of the menu)
     *
     * @param SimpleXMLElement $node menu item node
     *
     * @return bool
     */
    protected function isIndex($node)
    {
        foreach ($node->attributes() as $name => $value) {
            if ($name === 'index' && strval($value[0]) === 'true') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the item is hidden (that is skipped in the building of the menu)
     *
     * @param SimpleXMLElement $node menu item node
     *
     * @return bool
     */
    protected function isHidden($node)
    {
        foreach ($node->attributes() as $name => $value) {
            if ($name === 'display' && strval($value[0]) === 'false') {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the menu as HTML from raw PHP array definition.
     *
     * @param string $uri             URI of the current page
     * @param string $sourceDirectory Directory containing .rst files
     * @param string $baseHref        Base of link to be used if website is deployed in a folder URI
     *
     * @return string
     */
    protected function buildPhpMenu(string $uri, string $sourceDirectory, string $baseHref): string
    {
        $output = '';
        $menu = include $sourceDirectory.'/.index.php';

        foreach ($menu as $node) {
            if (!isset($node['path'], $node['name']) || ($node['hidden'] ?? false)) {
                continue;
            }

            $path = $node['path'];
            $name = $node['name'];
            $isDirectory = $node['directory'] ?? false;
            $name = htmlspecialchars(is_string($name) ? $name : strval($name[0]));
            $path = ltrim(strval(is_string($path) ? $path : $path[0]), '/');
            $href = '/'.$path;
            $root = $isDirectory ? $href : $this->trimExtension($href).'.html';
            $href = $isDirectory ? $href.'index.html' : $root;
            $selected = substr($uri, 0, strlen($root)) === $root;
            $output .= '<li><a href="'.$baseHref.$href.'" title="'.$name.'">';
            $output .= $selected ? '<strong>'.$name.'</strong>' : $name;
            $output .= '</a>';

            if ($selected && $isDirectory && file_exists($file = $sourceDirectory.'/'.$path.'.index.php')) {
                $upperPath = $path;
                $subMenu = include $file;

                $output .= '<ul>';

                foreach ($subMenu as $subNode) {
                    if (($subNode['index'] ?? false) || ($subNode['hidden'] ?? false)) {
                        continue;
                    }

                    $isDirectory = $subNode['directory'] ?? false;
                    $name = htmlspecialchars(strval($subNode['name'] ?? 'unknown'));
                    $href = '/'.$upperPath.ltrim(strval($subNode['path'] ?? 'unknown'), '/');
                    $root = $isDirectory ? $href : $this->trimExtension($href).'.html';
                    $href = $isDirectory ? $href.'index.html' : $root;
                    $output .= '<li><a href="'.$baseHref.$href.'" title="'.$name.'">';
                    $output .= substr($uri, 0, strlen($root)) === $root ? '<strong>'.$name.'</strong>' : $name;
                    $output .= '</a>';
                }

                $output .= '</ul>';
            }

            $output .= '</li>';
        }

        return $output;
    }

    /**
     * Return the menu as HTML from XML definition.
     *
     * @param string $uri             URI of the current page
     * @param string $sourceDirectory Directory containing .rst files
     * @param string $baseHref        Base of link to be used if website is deployed in a folder URI
     *
     * @return string
     */
    protected function buildXmlMenu(string $uri, string $sourceDirectory, string $baseHref): string
    {
        $output = '';
        $menu = simplexml_load_file($sourceDirectory.'/.index.xml');

        foreach ($menu->children() as $node) {
            $path = $node->xpath('path');
            $name = $node->xpath('name');

            if (!isset($path[0], $name[0]) || $this->isHidden($node)) {
                continue;
            }

            $isDirectory = $node->getName() === 'directory';
            $name = htmlspecialchars(strval($name[0]));
            $path = ltrim(strval($path[0]), '/');
            $href = '/'.$path;
            $root = $isDirectory ? $href : $this->trimExtension($href).'.html';
            $href = $isDirectory ? $href.'index.html' : $root;
            $selected = substr($uri, 0, strlen($root)) === $root;
            $output .= '<li><a href="'.$baseHref.$href.'" title="'.$name.'">';
            $output .= $selected ? '<strong>'.$name.'</strong>' : $name;
            $output .= '</a>';

            if ($selected && $isDirectory && file_exists($file = $sourceDirectory.'/'.$path.'.index.xml')) {
                $upperPath = $path;
                $subMenu = simplexml_load_file($file);

                $output .= '<ul>';

                foreach ($subMenu->children() as $subNode) {
                    if ($this->isIndex($subNode) || $this->isHidden($subNode)) {
                        continue;
                    }

                    $isDirectory = $subNode->getName() === 'directory';
                    $name = htmlspecialchars(strval($subNode->xpath('name')[0] ?? 'unknown'));
                    $href = '/'.$upperPath.ltrim(strval($subNode->xpath('path')[0] ?? 'unknown'), '/');
                    $root = $isDirectory ? $href : $this->trimExtension($href).'.html';
                    $href = $isDirectory ? $href.'index.html' : $root;
                    $output .= '<li><a href="'.$baseHref.$href.'" title="'.$name.'">';
                    $output .= substr($uri, 0, strlen($root)) === $root ? '<strong>'.$name.'</strong>' : $name;
                    $output .= '</a>';
                }

                $output .= '</ul>';
            }

            $output .= '</li>';
        }

        return $output;
    }

    /**
     * Return a path trimmed from its extension.
     *
     * @param string $path
     * @return string
     */
    protected function trimExtension(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (empty($extension)) {
            return $path;
        }

        return substr($path, 0, -1 - strlen($extension));
    }

    /**
     * Return the menu as HTML.
     *
     * @param string $uri             URI of the current page
     * @param string $sourceDirectory Directory containing .rst files
     * @param string $baseHref        Base of link to be used if website is deployed in a folder URI
     *
     * @return string
     */
    protected function buildMenu(string $uri, string $sourceDirectory, string $baseHref): string
    {
        if (file_exists($sourceDirectory.'/.index.php')) {
            return $this->buildPhpMenu($uri, $sourceDirectory, $baseHref);
        }

        if (file_exists($sourceDirectory.'/.index.xml')) {
            return $this->buildXmlMenu($uri, $sourceDirectory, $baseHref);
        }

        return '<!-- Menu index not found. -->';
    }

    public function getCommands(): array
    {
        return [
            'build' => Build::class,
        ];
    }
}
