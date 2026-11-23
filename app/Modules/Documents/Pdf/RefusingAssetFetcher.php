<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

use Mpdf\AssetFetcher;
use Mpdf\Exception\AssetFetchingException;

/**
 * mPDF's fetcher for a template print: it fetches nothing (ruling R8). By
 * default mPDF reads any local file or URL an `<img src>`, a CSS `url(...)`
 * or an `@import` names. A template print needs none: its images (the
 * letterhead) reach mPDF as `imageVars` (`var:` sources), which mPDF reads
 * from memory without asking the fetcher (mPDF 8.3.1,
 * Image/ImageProcessor::getImage()).
 *
 * mPDF's CssLoader and ImageProcessor take the concrete AssetFetcher, hence
 * the subclass; both catch AssetFetchingException and print on without the
 * asset. Nothing of the parent is used, so its constructor is not called.
 */
final class RefusingAssetFetcher extends AssetFetcher
{
    public function __construct() {}

    public function fetchDataFromPath($path, $originalSrc = null)
    {
        throw new AssetFetchingException('A template print fetches no file or URL.');
    }

    public function fetchLocalContent($path, $originalSrc)
    {
        throw new AssetFetchingException('A template print fetches no file or URL.');
    }

    public function fetchRemoteContent($path)
    {
        throw new AssetFetchingException('A template print fetches no file or URL.');
    }
}
