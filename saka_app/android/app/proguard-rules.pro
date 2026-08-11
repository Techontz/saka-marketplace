# Flutter's Android embedding is instantiated reflectively by the engine, so
# R8 cannot see the references and would strip it.
-keep class io.flutter.app.** { *; }
-keep class io.flutter.plugin.** { *; }
-keep class io.flutter.embedding.** { *; }
-dontwarn io.flutter.embedding.**

# flutter_secure_storage delegates to Tink for the Android Keystore path.
-keep class com.google.crypto.tink.** { *; }
-dontwarn com.google.crypto.tink.**
