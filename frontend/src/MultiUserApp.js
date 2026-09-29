import React, { useState, useEffect } from 'react';

const MultiUserApp = () => {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [tipoProveedor, setTipoProveedor] = useState('');
    const [detallesServicios, setDetallesServicios] = useState('');

    useEffect(() => {
        // Verificar si el usuario es administrador
        if (localStorage.getItem('userRole') !== 'admin') {
            window.location.href = '/login';
        }
    }, []);

    const handleLogin = async (e) => {
        e.preventDefault();
        // Lógica para login
    };

    const handleRegisterProvider = async (e) => {
        e.preventDefault();

        try {
            const response = await fetch('/api/solicitudes_proveedores/create_solicitud', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    nombre: email,
                    email: email,
                    tipo_proveedor_id: tipoProveedor,
                    detalles_servicios
                })
            });

            const data = await response.json();
            alert(data.message);
        } catch (error) {
            console.error('Error:', error);
            alert('Ocurrió un error al enviar la solicitud.');
        }
    };

    return (
        <div>
            {localStorage.getItem('userRole') === 'admin' ? (
                <div>
                    <h1>Panel de Administración</h1>
                    <button onClick={() => window.location.href = '/proveedores'}>Gestionar Proveedores</button>
                </div>
            ) : (
                <div>
                    <h1>Login</h1>
                    <form onSubmit={handleLogin}>
                        <input type="email" placeholder="Email" value={email} onChange={(e) => setEmail(e.target.value)} required />
                        <input type="password" placeholder="Password" value={password} onChange={(e) => setPassword(e.target.value)} required />
                        <button type="submit">Iniciar Sesión</button>
                    </form>
                </div>
            )}
        </div>
    );
};

export default MultiUserApp;
