<?php
declare(strict_types=1);

return [
    'routes/web.php.stub' =>
        'routes/web.php',

    'controllers/HomeController.php.stub' =>
        'app/Controllers/HomeController.php',

    'models/ContactModel.php.stub' =>
        'app/Models/ContactModel.php',

    'views/layouts/main.php.stub' =>
        'app/Views/layouts/main.php',

    'views/pages/home.php.stub' =>
        'app/Views/pages/home.php',

    'views/pages/about.php.stub' =>
        'app/Views/pages/about.php',

    'views/pages/projects.php.stub' =>
        'app/Views/pages/projects.php',

    'views/pages/contact.php.stub' =>
        'app/Views/pages/contact.php',

    'database/migrations/create_contacts.php.stub' =>
        'database/migrations/{{timestamp}}_create_yt_contacts.php',

    'database/seeders/DatabaseSeeder.php.stub' =>
        'database/seeders/DatabaseSeeder.php',

    'database/seeders/ContactsSeeder.php.stub' =>
        'database/seeders/ContactsSeeder.php',

    'config/database.php.stub' =>
        'config/database.scaffold.example.php',

    'docs/INSTALLATION.md.stub' =>
        'docs/INSTALLATION_CHECKLIST.md',
];
