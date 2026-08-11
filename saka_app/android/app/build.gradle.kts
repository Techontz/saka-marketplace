import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "tz.co.saka.app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlin {
        compilerOptions {
            jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
        }
    }

    defaultConfig {
        // Reverse-DNS of the client's own domain, saka.co.tz. This is
        // permanent: Play Store listings are keyed on it and it can never be
        // changed after the first upload.
        applicationId = "tz.co.saka.app"
        // Android 6.0. Below that, the WebP decoding and TLS 1.2 this app
        // relies on are unreliable, and the share of such devices in Tanzania
        // is now negligible.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    /*
     * Release signing.
     *
     * The keystore is NOT in this repository and never should be. Create
     * `android/key.properties` on the release machine with:
     *
     *   storeFile=/absolute/path/to/saka-release.jks
     *   storePassword=...
     *   keyAlias=saka
     *   keyPassword=...
     *
     * When that file is absent the release build falls back to the DEBUG key,
     * which produces a working APK for internal testing but one the Play Store
     * will reject — deliberately, so an unsigned build cannot be mistaken for a
     * shippable one.
     */
    signingConfigs {
        create("release") {
            val props = Properties()
            val propsFile = rootProject.file("key.properties")
            if (propsFile.exists()) {
                props.load(propsFile.inputStream())
                storeFile = file(props.getProperty("storeFile"))
                storePassword = props.getProperty("storePassword")
                keyAlias = props.getProperty("keyAlias")
                keyPassword = props.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (rootProject.file("key.properties").exists()) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }

            // R8 with resource shrinking. The app ships a five-weight font
            // family and a full Material icon set; without this the APK carries
            // every glyph and every unused resource.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }
}

flutter {
    source = "../.."
}
