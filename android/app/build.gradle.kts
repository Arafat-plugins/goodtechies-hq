import java.util.Properties

plugins {
    id("com.android.application")
}

// android/keystore/ is gitignored and lives only on the owner's machine. Without it a release
// build is unsigned and cannot update the installed app.
val keystoreProps = rootProject.file("keystore/keystore.properties")
val keystore = Properties().apply {
    if (keystoreProps.exists()) keystoreProps.inputStream().use { load(it) }
}

android {
    namespace = "com.goodtechies.erp"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.goodtechies.erp"
        minSdk = 23
        targetSdk = 35
        // Bump BOTH for every new APK (only needed when the shell itself changes — see README).
        versionCode = 2
        versionName = "1.0.1"
    }

    signingConfigs {
        create("release") {
            if (keystoreProps.exists()) {
                storeFile = rootProject.file("keystore/" + keystore.getProperty("storeFile"))
                storePassword = keystore.getProperty("storePassword")
                keyAlias = keystore.getProperty("keyAlias")
                keyPassword = keystore.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (keystoreProps.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation("com.google.androidbrowserhelper:androidbrowserhelper:2.7.3")
}
