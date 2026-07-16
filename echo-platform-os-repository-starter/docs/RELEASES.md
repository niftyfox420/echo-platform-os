# Release Process

1. Create a branch: `feature/<feature-name>`.
2. Make and test changes locally or on staging.
3. Update the plugin version and `CHANGELOG.md`.
4. Run the build script.
5. Validate PHP syntax.
6. Install the ZIP on staging using **Replace current with uploaded**.
7. Confirm activation, Mission Control, and the changed module.
8. Merge into `main`.
9. Create a Git tag such as `v1.3.0`.
10. Attach the generated ZIP to the GitHub Release.

Never test a new release first on the production site.
