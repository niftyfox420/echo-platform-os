# Module Contract

Each module should define:

- unique module ID
- display name and version
- dependencies
- required capabilities
- activation and migration hooks
- admin navigation registration
- event subscriptions
- health status callback

A disabled optional module must not break Core OS or unrelated modules.
