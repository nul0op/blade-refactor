# blade-refactor
move inline style from php blade template to external css

# ex call

with source blade files under /tmp/views with a name ending with '.blade.php'

```
php refactor.php -s /tmp/views -t /tmp/view-clean -c app.css -i .blade.php
```

then, you can find:
- all blade will be cleanup and duplicated under /tmp/view-clean
- and a single css file under ./app.css
