Minerva ORM
========

Simple ORM/DataMapper library using the Repository pattern.

## Usage

To create a new Repository class for your models, create a class that extends from `Minerva\Orm\BasePdoRepository`:

```php
<?php

namespace Acme\Example\Repository;

use Minerva\Orm\BasePdoRepository;

class BlogRepository extends BasePdoRepository
{
    
}
```

To instantiate your repository, simply call:

```php
$blogRepo = new PdoBlogRepository($pdo);
$blogs = $blogRepo->findAll(); // returns a list of all blog objectstore
$blog = $blogRepo->find(1); // returns blog with id 1
```

## License

MIT (see [LICENSE.md](LICENSE.md))

## Brought to you by the LinkORB Engineering team

<img src="http://www.linkorb.com/d/meta/tier1/images/linkorbengineering-logo.png" width="200px" /><br />
Check out our other projects at [linkorb.com/engineering](http://www.linkorb.com/engineering).

Btw, we're hiring!
