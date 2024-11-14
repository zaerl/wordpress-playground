import { StepDefinition } from '@wp-playground/blueprints';
import { logger } from '@php-wasm/logger';

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
type GAEvent = 'load' | 'step' | 'installPlugin' | 'installTheme' | 'error';

/**
 * Log a tracking event to Google Analytics
 * @param GAEvent The event name
 * @param Object Event data
 */
export const logTrackingEvent = (
	event: GAEvent,
	data?: { [key: string]: string }
) => {
	try {
		if (typeof window === 'undefined' || !window.gtag) {
			return;
		}
		window.gtag('event', event, data);
	} catch (error) {
		logger.warn('Failed to log tracking event', event, data, error);
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

	if (step.step === 'installPlugin' && (step as any).pluginData.slug) {
		logTrackingEvent('installPlugin', {
			slug: (step as any).pluginData.slug,
		});
	} else if (step.step === 'installTheme' && (step as any).themeData.slug) {
		logTrackingEvent('installTheme', {
			slug: (step as any).themeData.slug,
		});
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
