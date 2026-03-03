<?php
declare(strict_types=1);

namespace System\Testing\Sandbox;

use System\Session\SessionStore;

class SessionSandbox
{
    private ArraySessionAdapter $adapter;

    public function __construct()
    {
        $this->adapter = new ArraySessionAdapter();
    }

    public function init(): void
    {
        // Force inject our array adapter
        // Note: SessionStore is a static singleton. 
        // We need to reset it if it was already initialized, or just swap the adapter.
        
        // If SessionStore allows swapping adapter via init() repeatedly or setAdapter...
        // SessionStore::init() throws if started.
        // SessionStore::setAdapter() throws if started.
        
        // We might need to use reflection to reset SessionStore::$started if framework doesn't allow it.
        // Or assume we can bootstrap it once with ArrayAdapter and then just clear it.
        
        $this->resetSessionStoreState(); // Reflection hack if needed
        
        SessionStore::init($this->adapter);
    }

    public function reset(): void
    {
        $this->adapter->clear();
    }

    private function resetSessionStoreState(): void
    {
        try {
            $ref = new \ReflectionClass(SessionStore::class);
            
            $propStarted = $ref->getProperty('started');
            $propStarted->setAccessible(true);
            $propStarted->setValue(null, false);

            $propAdapter = $ref->getProperty('adapter');
            $propAdapter->setAccessible(true);
            $propAdapter->setValue(null, null);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
