import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    let channel = FlutterMethodChannel(
      name: "shahead/security",
      binaryMessenger: engineBridge.binaryMessenger
    )

    channel.setMethodCallHandler { call, result in
      switch call.method {
      case "preventScreenshotOn":
        self.startMonitoring(channel)
        result(true)
      case "preventScreenshotOff":
        self.stopMonitoring()
        result(true)
      default:
        result(FlutterMethodNotImplemented)
      }
    }
  }

  /// iOS does not allow blocking screenshots via FLAG_SECURE; the strongest
  /// supported measure is DETECTION: we observe the system screenshot +
  /// screen-capture notifications and tell Flutter, which can then pause
  /// protected video / warn the student. External cameras are out of scope.
  private func startMonitoring(_ channel: FlutterMethodChannel) {
    NotificationCenter.default.addObserver(
      forName: UIApplication.userDidTakeScreenshotNotification,
      object: nil,
      queue: .main
    ) { [weak channel] _ in
      channel?.invokeMethod("onScreenshot", arguments: nil)
    }

    NotificationCenter.default.addObserver(
      forName: UIScreen.capturedDidChangeNotification,
      object: nil,
      queue: .main
    ) { [weak channel] _ in
      channel?.invokeMethod("onScreenRecord", arguments: UIScreen.main.isCaptured)
    }
  }

  private func stopMonitoring() {
    NotificationCenter.default.removeObserver(
      self,
      name: UIApplication.userDidTakeScreenshotNotification,
      object: nil
    )
    NotificationCenter.default.removeObserver(
      self,
      name: UIScreen.capturedDidChangeNotification,
      object: nil
    )
  }
}
