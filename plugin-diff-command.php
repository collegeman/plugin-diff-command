<?php
require_once('vendor/autoload.php');

use \WP_CLI\Utils;

/**
 * plugin diff command for WP-CLI
 * Copyright (c) 2014 Fat Panda, LLC
 * MIT Licensed
 */
class FatPanda_Plugin_Command extends WP_CLI_Command
{

  /**
   * Detect differences between the installed copy of a plugin and a copy of that plugin hosted on WordPress.org.
   *
   * ## OPTIONS
   *
   * <name>
   * : The plugin name, e.g., "hello-dolly".
   *
   * [<compare-version>]
   * : Optional, the version to compare, e.g., "1.0.0" or "latest"
   *
   * [--report=<type>]
   * : Optional, report to produce: "simple" or "unified"
   *
   * ## EXAMPLES
   *
   *      wp plugin diff hello-dolly
   *      wp plugin diff hello-dolly 0.1.0
   *      wp plugin diff hello-dolly latest
   *
   * @synopsis <plugin-name> [<compare-version>] [--report=<type>]
   */
  function diff($args, $assoc_args)
  {
    if (empty($assoc_args['report'])) {
      $assoc_args['report'] = 'simple';
    }

    list($in_plugin_name, $in_compare_version) = $args;

    $plugin = false;
    $plugins = get_plugins(  '/' . plugin_basename( dirname( $file ) ) );
    foreach($plugins as $file => $meta) {
      $name = Utils\get_plugin_name($file);
      if ($name === $in_plugin_name) {
        $meta['__FILE__'] = $file;
        $meta['__PATH__'] = ABSPATH.'wp-content/plugins/'.dirname($file);
        $plugin = $meta;
        break;
      }
    }

    if (!$plugin) {
      WP_CLI::warning("Plugin is not installed: {$in_plugin_name}");
    }

    $download_version = $meta['Version'];
    if ($in_compare_version && $download_version !== $in_compare_version) {
      WP_CLI::confirm("Compare {$in_plugin_name} to {$in_compare_version} instead of the installed version, {$meta['Version']}?");
    }

    $dir = $this->downloadAndUnzipPlugin($in_plugin_name, $download_version);
    if (!$dir) {
      return false;
    }

    $this->compare($name, $dir.'/'.$name, $meta['__PATH__'], $assoc_args['report']);

    $this->rmdir($dir);
  }

  /**
   * Detect differences between the installed copy of a plugin and a copy of that plugin hosted on WordPress.org.
   *
   * @alias diff-all
   *
   * @synopsis
   */
  function diff_all($args, $assoc_args)
  {
    $plugins = get_plugins(  '/' );
    foreach($plugins as $file => $meta) {
      $name = Utils\get_plugin_name($file);
      $this->diff(array($name), array());
    }
  }

  /**
   * Recursively compare two folders' content, and report findings.
   * @param String Plugin name
   * @param String Path to baseline
   * @param String Path to local (comparison) copy
   * @param String Report type; options are "simple" and "unified"; default is "simple"
   * @return boolean true when the same; otherwise, false
   */
  protected function compare($name, $baseline, $local, $report_type = 'simple', &$finfo = null)
  {
    if (!in_array($report_type, array('simple', 'unified'))) {
      WP_CLI::error("Invalid report type: {$report_type}. Valid types are 'simple' and 'unified'");
    }

    if (is_null($finfo)) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
    }

    foreach(glob("{$baseline}/*") as $file) {
      $baseline_subpath = str_replace($baseline, '', $file);
      $local_comparison = $local.$baseline_subpath;
      if (!file_exists($local_comparison)) {
        WP_CLI::warning("[{$name}] Missing: {$local_comparison}");
        continue;
      }

      if (is_dir($file) && !is_dir($local_comparison)) {
        WP_CLI::warning("[{$name}] Should be a directory: {$local_comparison}");
        continue;
      } else {
        $this->compare($name, $file, $local_comparison, $report_type, $finfo);
      }

      $mimetype = finfo_file($finfo, $local_comparison);
      if (md5_file($file) !== md5_file($local_comparison)) {
        if ($report_type === 'unified' && strpos($mimetype, 'text') !== false) {
          $this->diffReport($name, $report_type, $file, $local_comparison);
        } else {
          WP_CLI::warning("[{$name}] Checksums do not match: {$local_comparison}");
        }
      }
    }
  }

  protected function diffReport($name, $report_type, $baseline, $local)
  {
    WP_CLI::line("--- [{$name}] {$baseline}");
    WP_CLI::line("+++ [{$name}] {$local}");
    $a = explode("\n", file_get_contents($baseline));
    $b = explode("\n", file_get_contents($local));
    $diff = new \Diff($a, $b, array(
      'ignoreWhitespace' => true
    ));
    $renderer = new \Diff_Renderer_Text_Unified;
    WP_CLI::line($diff->Render($renderer));
  }

  /**
   * Download the specified plugin from WordPress.org and unpack
   * the archive into a temporary path.
   * @param String The plugin name
   * @param String (optional) The version to download; default is "latest"
   * @return String The path to the unpacked plugin.
   */
  protected function downloadAndUnzipPlugin($name, $version = 'latest')
  {
    $version_ext = $version && $version !== 'latest' ? ".{$version}" : '';

    $url = "https://downloads.wordpress.org/plugin/{$name}{$version_ext}.zip";

    if (is_wp_error($zipFile = download_url($url))) {
      WP_CLI::warning("Failed to download {$name}: ".$zipFile->get_error_message());
      return false;
    }

    WP_Filesystem();

    $tempPath = $this->createTempPath();
    if (is_wp_error($result = unzip_file($zipFile, $tempPath))) {
      unlink($tempPath);
      WP_CLI::warning("Unable to extract downloaded copy of {$name} {$version}: ".$result->get_error_message());
      return false;
    }

    unlink($zipFile);

    return $tempPath;
  }


  /**
   * Create a temporary folder.
   * @return String The path to the folder.
   */
  protected function createTempPath()
  {
    $tempfile = tempnam(sys_get_temp_dir(), '');
    if (file_exists($tempfile)) {
      unlink($tempfile);
    }
    mkdir($tempfile);
    if (!is_dir($tempfile)) {
      WP_CLI::error("Failed to create temporary directory {$tempfile}");
    }
    return $tempfile;
  }

  /**
   * Recursively remove a folder and its contents.
   * @param String The path to remove
   */
  protected function rmdir($path)
  {
    if (!is_dir($path)) {
      return false;
    }
    foreach(glob("{$path}/{,.}*", GLOB_BRACE) as $file) {
      if ($file !== "{$path}/." && $file !== "{$path}/..") {
        if (is_dir($file)) {
          $this->rmdir($file);
        } else {
          unlink($file);
        }
      }
    }
    rmdir($path);
  }

}

WP_CLI::add_command('plugin', 'FatPanda_Plugin_Command');
