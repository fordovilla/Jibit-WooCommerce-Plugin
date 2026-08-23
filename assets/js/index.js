const settings = window.wc.wcSettings.getSetting('WC_Jibit_data', {});

const JibitContent = () => {
    return window.wp.element.createElement('div', {
        dangerouslySetInnerHTML: { __html: settings.description || '' }
    });
};

const JibitLabel = () => {
    return window.wp.element.createElement('span', {
        style: { display: 'flex', alignItems: 'center', gap: '10px' }
    }, [
        settings.title || 'Jibit',
        settings.icon ? window.wp.element.createElement('img', {
            key: 'icon',
            src: settings.icon,
            style: { height: '24px' },
            alt: 'Jibit'
        }) : null
    ]);
};

window.wc.wcBlocksRegistry.registerPaymentMethod({
    name: 'WC_Jibit',
    label: window.wp.element.createElement(JibitLabel),
    content: window.wp.element.createElement(JibitContent),
    edit: window.wp.element.createElement(JibitContent),
    canMakePayment: () => true,
    ariaLabel: settings.title || 'Jibit',
    supports: {
        features: settings.supports || ['products'],
    },
});
