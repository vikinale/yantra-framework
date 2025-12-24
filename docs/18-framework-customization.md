# 18. Framework Customization

## 18.1 Extending at the application layer
Yantra is designed to be extended outside framework core:
- Custom middleware
- Custom validators/sanitizers
- Custom services and domain layers

## 18.2 Overriding behavior
Prefer composition over modifying framework files. If patching core, keep changes isolated and versioned.

## 18.3 Adding a DI container (optional)
Keep the container in App\... so the framework remains minimal.
