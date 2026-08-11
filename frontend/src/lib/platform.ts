import { Capacitor } from '@capacitor/core'

/**
 * Where the app is running.
 *
 * `isNativeApp` is true inside the iOS/Android Capacitor shell, false in a
 * browser. Code paths that only make sense on a device — saving a file to the
 * device, native share — branch on this so the web build stays byte-for-byte
 * what it always was.
 */
export const isNativeApp = Capacitor.isNativePlatform()

/** 'web' | 'ios' | 'android'. */
export const platform = Capacitor.getPlatform()
