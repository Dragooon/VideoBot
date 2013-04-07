<?php
/**
 * Main video bot file
 *
 * @package VideoBot
 * @author Shitiz Garg (mail@dragooon.net)
 * @license New BSD License
 * @copyright 2013 Shitiz Garg
 */

define('TVDB_API_KEY', 'FD313B33985AD438');
define('TVDB_URL', 'http://thetvdb.com');
define('TMDB_API_KEY', '980ffe84b580e8a5694f2d687d26d3a9');

date_default_timezone_set('Asia/Calcutta');

require_once('TvDb/Client.php');
require_once('TvDb/Serie.php');
require_once('TvDb/Episode.php');
require_once('TvDb/Banner.php');
require_once('TvDb/Exception.php');
require_once('TvDb/XmlException.php');
require_once('TvDb/CurlException.php');
require_once('tmdb.php');

$arguments = array();
foreach ($argv as $arg)
    if (substr($arg, 0, 2) == '--' && strpos($arg, '='))
        $arguments[substr($arg, 2, strpos($arg, '=') - 2)] = substr($arg, strpos($arg, '=') + 1);

$file = str_replace('"', '', $arguments['file']);
$dest = str_replace('"', '', $arguments['dest']);
$conflict = !empty($arguments['conflict']) ? $arguments['conflict'] : 'skip';
$mode = !empty($arguments['mode']) ? $arguments['mode'] : 'copy';
$xbmc = !empty($arguments['xbmc']) ? $arguments['xbmc'] : '';

if (empty($dest))
    die('No destination specified');

if (is_file($file))
    handleFile($file, $dest, $mode, $conflict);
elseif (is_dir($file))
    handleFolder($file, $dest, $mode);
else
    echo "Invalid file speciifed\n";

// Tell XBMC to scan it's library again
if (!empty($xbmc))
{
    $request = "http://" . $xbmc . "/jsonrpc?request=%7B%22id%22%3A1%2C%22method%22%3A%22VideoLibrary.Scan%22%2C%22params%22%3A%5B%5D%2C%22jsonrpc%22%3A%222.0%22%7D";
    file_get_contents($request);
}

/**
 * Recursively handles a folder
 *
 * @param string $file
 * @param string $dest
 * @param string $mode
 * @param string $conflict
 * @return void
 */
function handleFolder($file, $dest, $mode = 'delete', $conflict = 'skip')
{
    $files = scandir($file);
    foreach ($files as $f)
    {
        if ($f == '.' || $f == '..')
            continue;

        if (is_dir($file . '/' . $f))
            handleFolder($file . '/' . $f, $dest, $mode, $conflict);
        else
            handleFile($file . '/' . $f, $dest, $mode, $conflict);
    }
}

/**
 * Tries to scrape the movie or episode title and handles the moving/copying
 *
 * @param string $file
 * @param string $dest
 * @param string $mode delete or copy
 * @param string $conflict skip or delete
 * @return bool
 */
function handleFile($file, $dest, $mode = 'delete', $conflict = 'skip')
{
    $filename = basename($file);
    $ext = pathinfo($file, PATHINFO_EXTENSION);

    if (!in_array(strtolower($ext), array('mkv', 'm4v', 'mp4', 'avi', 'rmvb', 'rm', 'webm', 'ogg', 'mpeg', 'mpg', 'wmv', 'flv')))
        return true;

    // Remove commonly used words in files to ease our scraping
    $common_words = array('720p', 'hdtv', '480p', '1080p', 'dvdscr', 'dvdrip', 'camrip', 'x264', 'bluray', 'bdrip', 'xvid', 'divx', 'aac', 'mp3');
    $filename = str_ireplace($common_words, '', $filename);

    // Attempt to fetch the season number and episode for this TV Episode
    $regex = array(
        '/[Ss]([0-9]+)[][ ._-]*[Ee]([0-9]+)([^\\/]*)$/',
    );
    foreach ($regex as $reg)
    {
        preg_match($reg, $filename, $matches);
        if (!empty($matches[1]) && !empty($matches[2]))
            break;
    }

    $filename = str_replace(array('.', '_', '-'), ' ', $filename);
    $fileparts = explode(' ', $filename);
    foreach ($fileparts as $k => $part)
        if (empty($part))
            unset($fileparts[$k]);

    if (empty($matches[1]) || empty($matches[2]) || !($episodeInfo = scrapeEpisode($fileparts, $matches[1], $matches[2])))
    {
        // If we didn't get an episode with the given filename, try to fetch it a a movie
        $movieTitle = scrapeMovie($fileparts);
            
        if (empty($movieTitle))
        {
            echo "\nNo matches for $file";
            return;
        }

        $dest = $dest . '/Movies';
        if (!file_exists($dest))
            mkdir($dest);

        $dest = $dest . '/' . $movieTitle . '.' . pathinfo($file, PATHINFO_EXTENSION);
 
        if (file_exists($dest) && $conflict == 'delete')
            @unlink($dest);
        elseif (file_exists($dest))
            return true;

        if ($mode == 'delete')
            rename($file, $dest);
        else
            copy($file, $dest);
    }
    else
    {
        $series = $episodeInfo[0];
        $episode = $episodeInfo[1];
        $filename = $series->name . ' - ' . 'S' . sprintf('%02d', $episode->season) . 'E' . sprintf('%02d', $episode->number) . ' - ' . $episode->name . '.' . pathinfo($file, PATHINFO_EXTENSION);

        if (!file_exists($dest . '/TV Shows'))
            mkdir($dest . '/TV Shows');
        if (!file_exists($dest . '/TV Shows/' . $series->name))
            mkdir($dest . '/TV Shows/' . $series->name);
        if (!file_exists($dest . '/TV Shows/' . $series->name . '/Season ' . $episode->season))
            mkdir($dest . '/TV Shows/' . $series->name . '/Season ' . $episode->season);

        $dest = $dest . '/TV Shows/' . $series->name . '/Season ' . $episode->season . '/' . $filename;

        if (file_exists($dest) && $conflict == 'delete')
            @unlink($dest);
        elseif (file_exists($dest))
            return true;

        echo "\nMoving file to $dest";

        $time = microtime(true);
        if ($mode == 'delete')
            rename($file, $dest);
        else
            copy($file, $dest);

        $time_taken = microtime(true) - $time;
        echo "\nMoved the file in " . $time_taken . ' seconds';
    }

    echo "\n$file => $dest\n";
}

/**
 * Attempts to fetch a movie's name from the given fulename
 *
 * @param array $fileparts
 * @return string
 */
function scrapeMovie($fileparts)
{
    $tmdb = new TMDb(TMDB_API_KEY);

    $movie = array();

    // Attempt to fetch the movie by working on the name backwards
    // i.e., remove a word every time we don't get a match
    do {
        $movie = $tmdb->searchMovie(implode(' ', $fileparts));
        array_pop($fileparts);
    } while (empty($movie['results']) && !empty($fileparts));

    if (!empty($movie['results']))
        return $movie['results'][0]['title'] . ' (' . strftime('%G', strtotime($movie['results'][0]['release_date'])) . ')';
    else
        return false;
}
/**
 * Attempts to fetch the series' name from the given filename
 * and then the particular episode specified in the arguments
 *
 * @param array $fileparts
 * @param int $season
 * @param int $episode
 * @return array(TvDb\Serie, TvDb\Episode)
 */
function scrapeEpisode($fileparts, $season, $episode)
{
    if (empty($season) || empty($episode))
        return false;

    $tvdb = new \TvDb\Client(TVDB_URL, TVDB_API_KEY);

    // Attempt to fetch the series by working on the name backwards
    // i.e., remove a word every time we don't get a match
    $series = array();
    do {
        $series = $tvdb->getSeries(implode(' ', $fileparts));
        array_pop($fileparts);
    } while (empty($series) && !empty($fileparts));

    // Found a match? yay!
    if (!empty($series))
    {
        echo "\nDetected " . $series[0]->name;

        // Fetch this episode
        try {
            $episode = $tvdb->getEpisode($series[0]->id, $season, $episode);
        } catch (Exception $e)
        {
            return false;
        }

        if (!empty($episode))
            return array($series[0], $episode);
    }

    return false;
}