import { StepDefinition } from '@wp-playground/blueprints';

/**
 * Declare the global window.gtag function
 */
declare global {
	interface Window {
		gtag: any;
	}
}

/**
 * Google Analytics event names
 */
type GAEvent = 'load' | 'step' | 'install' | 'error';

/**
 * Log a tracking event to Google Analytics
 * @param GAEvent The event name
 * @param Object Event data
 */
export const logTrackingEvent = (
	event: GAEvent,
	data?: { [key: string]: string }
) => {
	if (typeof window === 'undefined' || !window.gtag) {
		return;
	}
	window.gtag('event', event, data);
};

/**
 * Log Plugin install events
 * @param step The Blueprint step
 */
export const logPluginInstallEvent = (step: StepDefinition) => {
	const pluginData = (step as any).pluginData;
	if (pluginData.slug) {
		logTrackingEvent('install', {
			plugin: pluginData.slug,
		});
	} else if (pluginData.url) {
		logTrackingEvent('install', {
			plugin: pluginData.url,
		});
	}
};

/**
 * Log Theme install events
 * @param step The Blueprint step
 */
export const logThemeInstallEvent = (step: StepDefinition) => {
	const themeData = (step as any).themeData;
	if (themeData.slug) {
		logTrackingEvent('install', {
			theme: themeData.slug,
		});
	} else if (themeData.url) {
		logTrackingEvent('install', {
			theme: themeData.url,
		});
	}
};

/**
 * Log Blueprint step events
 * @param step The Blueprint step
 */
export const logBlueprintStepEvent = (step: StepDefinition) => {
	/**
	 * Log the names of provided Blueprint's steps.
	 * Only the names (e.g. "runPhp" or "login") are logged. Step options like
	 * code, password, URLs are never sent anywhere.
	 */
	logTrackingEvent('step', { step: step.step });

	if (step.step === 'installPlugin') {
		logPluginInstallEvent(step);
	} else if (step.step === 'installTheme') {
		logThemeInstallEvent(step);
	}
};

/**
 * Log error events
 *
 * @param error The error
 */
export const logErrorEvent = (source: string) => {
	logTrackingEvent('error', {
		source,
	});
};
