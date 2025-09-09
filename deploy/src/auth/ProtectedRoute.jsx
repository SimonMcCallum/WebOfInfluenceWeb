import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "./AuthProvider";

export default function ProtectedRoute() {
  const { user, initializing } = useAuth();
  const loc = useLocation();

  if (initializing) {
    return (
      <div style={{ padding: 24, textAlign: 'center' }}>
        <h2>Checking authentication…</h2>
      </div>
    );
  }

  return user ? <Outlet /> : <Navigate to="/login" replace state={{ from: loc }} />;
}
