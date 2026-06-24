#!/bin/sh
exec "/Users/hoanganhkgdev/Library/Application Support/Herd/bin/php" /Users/hoanganhkgdev/Herd/ghpgvn/tratailieu/artisan queue:work --sleep=3 --tries=3 --max-time=3600
