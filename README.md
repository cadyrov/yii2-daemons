daemons
=======
daemons on php structure

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist cadyrov/yii2-daemons "*"
```

or add

```
"cadyrov/yii2-daemons": "*"
```

to the require section of your `composer.json` file.


Install
-----

Once the extension is installed, simply use it in your code by  :

1. Create in you console controllers path file ObserverController.php with following content:
```
<?php

namespace console\controllers;

class ObserverController extends \cadyrov\daemons\controllers\ObserverController
{
    /**
     * @return array
     */
    protected function defineJobs()
    {
        sleep($this->sleep);
        //TODO: modify list, or get it from config, it does not matter
        $daemons = [
            ['className' => 'OneDaemonController', 'enabled' => true],
            ['className' => 'AnotherDaemonController', 'enabled' => false]
        ];
        return $daemons;
    }
}
```
2. No one checks the Watcher. Watcher should run continuously. Add it to your crontab:
```
* * * * * /path/to/yii/project/yii observer-daemon --demonize=1
```
Observer can't start twice, only one instance can work in the one moment.

Usage
-----
### Create new daemons
1. Create in you console controllers path file {NAME}DaemonController.php with following content:
```
<?php

namespace console\controllers;

use \cadyrov\daemons\Daemon;

class {NAME}DaemonController extends Daemon
{
    /**
     * @return array
     */
    protected function defineJobs()
    {
        /*
        TODO: return task list, extracted from DB, queue managers and so on.
        Extract tasks in small portions, to reduce memory usage.
        */
    }
    /**
     * @return jobtype
     */
    protected function doJob($job)
    {
        /*
        TODO: implement you logic
        Don't forget to mark task as completed in your task source
        */
    }
}
```
2. Implement logic.
3. Add new daemon to daemons list in watcher.
