# Logging policy

All PHP components must use `App\Infrastructure\Logger` for operational messages, warnings and errors.  
This logger writes to `logs/app-YYYY-MM-DD.log` (or `sys_get_temp_dir()` as fallback) creating a new file every day.  
Usage example:

```php
$logger = new \App\Infrastructure\Logger();
$logger->info('Starting process...');
$logger->warning('Missing optional input.');
$logger->error('Operation failed: ' . $exception->getMessage());
```

When implementing new methods/controllers/services, instantiate the logger once per class (constructor injection or instantiation) and log relevant warnings/errors inside each method.  
Avoid `error_log` or manual `file_put_contents`; rely on `Logger` so rotation and file paths remain consistent.
