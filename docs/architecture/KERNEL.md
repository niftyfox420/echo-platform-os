# Core OS Kernel

Milestone 2 introduces a compatibility-first kernel around the existing working plugin.

## Responsibilities

- Service container
- Typed event bus
- Module registry and dependency validation
- Central bounded logger
- Module health reporting
- Compatibility adapters for existing production classes

## Safety strategy

The working services are still constructed by the existing `Echo_Motorworks_Core` bootstrap. The kernel initially registers them as legacy modules for observability and dependency mapping only. Future releases can migrate one module at a time to native `Echo_OS_Module` implementations without a flag-day rewrite.
