# Legacy Areas Requiring Staged Cleanup

The platform still contains domain-specific legacy artifacts from earlier products. They should be removed only after consumer/runtime verification.

Examples include barber-specific user flags/observers/templates, legacy appointment tables alongside order-based appointments, production/event/ticket structures, and old analytics logic embedded in large models.

Recommended cleanup order:

1. identify active routes and frontend consumers;
2. add regression coverage around active behavior;
3. separate shared platform behavior from niche-specific modules;
4. migrate consumers;
5. remove dead classes/routes/templates/columns in dedicated migrations;
6. validate production data before destructive schema cleanup.

Do not combine destructive legacy cleanup with security-sensitive platform migrations unless the dependency is conclusively dead.
