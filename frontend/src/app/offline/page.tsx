import type { Metadata } from 'next';
import OfflineView from './offline-view';

/**
 * Static, script-light offline fallback. Precached by the service worker and
 * served for any failed navigation, so the student always gets a branded Arabic
 * page instead of the browser's dinosaur.
 *
 * It never renders account data, which is why it is safe to cache and to share
 * across users on the same device.
 */
export const metadata: Metadata = {
  title: 'غير متصل',
  robots: { index: false, follow: false },
};

export const dynamic = 'force-dynamic';

export default function OfflinePage() {
  return <OfflineView />;
}
