import java.util.Properties

plugins {
    id("com.android.application")
}

// Notifications inside the app (1.0.6): Firebase reads `app/google-services.json`, which comes from
// the goodERP Firebase project and is gitignored. Without it the app still builds and works —
// only its own notifications stay off (`AppPush.isAvailable()`), as in 1.0.5.
val firebaseConfig = file("google-services.json")
if (firebaseConfig.exists()) {
    apply(plugin = "com.google.gms.google-services")
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
        versionCode = 7
        versionName = "1.0.6"
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

    testOptions {
        unitTests {
            isIncludeAndroidResources = true
        }
    }
}

dependencies {
    implementation("com.google.androidbrowserhelper:androidbrowserhelper:2.7.3")
    implementation(platform("com.google.firebase:firebase-bom:33.7.0"))
    implementation("com.google.firebase:firebase-messaging")
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.robolectric:robolectric:4.14.1")
    testImplementation("androidx.test:core:1.6.1")
}
