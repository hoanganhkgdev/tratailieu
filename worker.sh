#!/bin/sh
exec "/Users/nguyenhoanganh/Library/Application Support/Herd/bin/php" /Users/nguyenhoanganh/Project/ghpgvn/tratailieu/artisan queue:work database --sleep=3 --tries=2 --timeout=300 --max-jobs=1000 --max-time=3600
