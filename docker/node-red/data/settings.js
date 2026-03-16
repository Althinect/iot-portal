module.exports = {
    flowFile: 'flows.json',
    uiPort: process.env.PORT || 1880,
    diagnostics: {
        enabled: true,
        ui: true,
    },
    runtimeState: {
        enabled: false,
        ui: false,
    },
    editorTheme: {
        projects: {
            enabled: false,
        },
    },
    functionExternalModules: false,
    exportGlobalContextKeys: false,
};
