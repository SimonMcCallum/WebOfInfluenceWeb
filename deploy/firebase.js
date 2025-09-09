import { initializeApp } from "firebase/app";
import { getAuth } from "firebase/auth";

const firebaseConfig = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket: "webofinfluence-a55f9.firebasestorage.app",
  messagingSenderId: "59831952694",
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
  measurementId: "G-ZJ164RQBE7"
};

const app = initializeApp(firebaseConfig);
export const auth = getAuth(app);
