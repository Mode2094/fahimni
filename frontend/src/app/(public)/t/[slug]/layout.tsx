/**
 * Static iOS PWA tags for teacher pages. No server fetch needed: iOS reads
 * apple-mobile-web-app-capable for standalone mode; the app title/icon/theme
 * come from the page's generateMetadata/generateViewport (which override the
 * root layout). These two tags don't exist in the root head, so there is no
 * duplication.
 */
export default function TeacherLandingLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      {children}
      <meta name="mobile-web-app-capable" content="yes" />
      <meta name="apple-mobile-web-app-capable" content="yes" />
      <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    </>
  );
}