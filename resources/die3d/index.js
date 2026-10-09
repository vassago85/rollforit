import {
  AmbientLight,
  BufferGeometry,
  CanvasTexture,
  DirectionalLight,
  Float32BufferAttribute,
  Mesh,
  MeshBasicMaterial,
  MeshStandardMaterial,
  PerspectiveCamera,
  PlaneGeometry,
  Quaternion,
  Scene,
  SRGBColorSpace,
  Vector3,
  WebGLRenderer,
} from 'three';

/*
 * Pentagonal trapezohedron (d10). Ring heights follow from requiring each kite
 * to be planar: z = h * (1 - cos 36°) / (1 + cos 36°).
 */
const APEX = 1.15;
const RADIUS = 1;
const RING = (APEX * (1 - Math.cos(Math.PI / 5))) / (1 + Math.cos(Math.PI / 5));
const UPPER_NUMBERS = [1, 3, 5, 7, 9];
const GRAVITY = 30;
const SETTLE_MS = 500;

function buildDie() {
  const top = new Vector3(0, APEX, 0);
  const bottom = new Vector3(0, -APEX, 0);
  const upper = [];
  const lower = [];
  for (let k = 0; k < 5; k++) {
    const a = (k * 2 * Math.PI) / 5;
    const b = a + Math.PI / 5;
    upper.push(new Vector3(RADIUS * Math.cos(a), RING, RADIUS * Math.sin(a)));
    lower.push(new Vector3(RADIUS * Math.cos(b), -RING, RADIUS * Math.sin(b)));
  }

  // Opposite faces sum to 11. Upper kite k (centred at 36° + 72k) is opposite lower kite (k + 2) % 5.
  const faces = [];
  for (let k = 0; k < 5; k++) {
    faces.push({ number: UPPER_NUMBERS[k], quad: [top, upper[k], lower[k], upper[(k + 1) % 5]] });
  }
  for (let k = 0; k < 5; k++) {
    const opposite = (k + 3) % 5;
    faces.push({ number: 11 - UPPER_NUMBERS[opposite], quad: [bottom, lower[k], upper[(k + 1) % 5], lower[(k + 1) % 5]] });
  }

  const positions = [];
  const normals = [];
  const uvs = [];
  const geometry = new BufferGeometry();
  const info = {};

  faces.forEach((face, index) => {
    let [a, b, c, d] = face.quad;
    const centre = new Vector3().add(a).add(b).add(c).add(d).multiplyScalar(0.25);
    let normal = new Vector3().subVectors(b, a).cross(new Vector3().subVectors(c, a)).normalize();
    if (normal.dot(centre) < 0) {
      [b, d] = [d, b];
      normal.negate();
    }

    const upAxis = new Vector3().subVectors(a, c).normalize();
    const rightAxis = new Vector3().crossVectors(upAxis, normal).normalize();
    const anchor = centre.clone().lerp(c, 0.15);
    const verts = [a, b, c, d];
    const extent = Math.max(...verts.map((v) => {
      const rel = new Vector3().subVectors(v, anchor);
      return Math.max(Math.abs(rel.dot(rightAxis)), Math.abs(rel.dot(upAxis)));
    })) * 2;
    const uv = (v) => {
      const rel = new Vector3().subVectors(v, anchor);
      return [0.5 + rel.dot(rightAxis) / extent, 0.5 + rel.dot(upAxis) / extent];
    };

    const start = positions.length / 3;
    [[a, b, c], [a, c, d]].forEach((tri) => tri.forEach((v) => {
      positions.push(v.x, v.y, v.z);
      normals.push(normal.x, normal.y, normal.z);
      uvs.push(...uv(v));
    }));
    geometry.addGroup(start, 6, index);
    info[face.number] = { normal, up: upAxis };
  });

  geometry.setAttribute('position', new Float32BufferAttribute(positions, 3));
  geometry.setAttribute('normal', new Float32BufferAttribute(normals, 3));
  geometry.setAttribute('uv', new Float32BufferAttribute(uvs, 2));

  const vertices = [new Vector3(0, APEX, 0), new Vector3(0, -APEX, 0), ...upper, ...lower];
  const inradius = info[1].normal.dot(new Vector3(0, APEX, 0));

  return { geometry, faces, info, vertices, inradius };
}

function numberTexture(number, dieColor, numberColor) {
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = 256;
  const g = canvas.getContext('2d');
  g.fillStyle = dieColor;
  g.fillRect(0, 0, 256, 256);
  g.fillStyle = numberColor;
  g.font = 'bold 104px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
  g.textAlign = 'center';
  g.textBaseline = 'middle';
  g.fillText(String(number), 128, 132);
  if (number === 6 || number === 9) g.fillRect(104, 178, 48, 7);
  const texture = new CanvasTexture(canvas);
  texture.colorSpace = SRGBColorSpace;
  texture.anisotropy = 4;
  return texture;
}

function shadowTexture() {
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = 128;
  const g = canvas.getContext('2d');
  const grad = g.createRadialGradient(64, 64, 4, 64, 64, 64);
  grad.addColorStop(0, 'rgba(0,0,0,0.35)');
  grad.addColorStop(1, 'rgba(0,0,0,0)');
  g.fillStyle = grad;
  g.fillRect(0, 0, 128, 128);
  return new CanvasTexture(canvas);
}

const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);
const rand = (min, max) => min + Math.random() * (max - min);

export function create(container, options = {}) {
  const dieColor = options.dieColor || '#1f2937';
  const numberColor = options.numberColor || '#ffffff';
  const width = container.clientWidth || 300;
  const height = container.clientHeight || 180;

  const renderer = new WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setSize(width, height);
  container.innerHTML = '';
  container.appendChild(renderer.domElement);

  const scene = new Scene();
  const camera = new PerspectiveCamera(36, width / height, 0.1, 100);
  camera.position.set(0, 6.4, 2.2);
  camera.lookAt(0, 0.4, 0);
  scene.add(new AmbientLight(0xffffff, 1.1));
  const sun = new DirectionalLight(0xffffff, 1.8);
  sun.position.set(3, 8, 5);
  scene.add(sun);

  const die = buildDie();
  const materials = die.faces.map((f) => new MeshStandardMaterial({
    map: numberTexture(f.number, dieColor, numberColor), roughness: 0.45, metalness: 0.05,
  }));
  const mesh = new Mesh(die.geometry, materials);
  scene.add(mesh);

  const shadow = new Mesh(new PlaneGeometry(2.6, 2.6), new MeshBasicMaterial({ map: shadowTexture(), transparent: true, depthWrite: false }));
  shadow.rotation.x = -Math.PI / 2;
  shadow.position.y = 0.001;
  scene.add(shadow);

  const halfWidth = Math.max(1.2, (width / height) * 1.5);
  const halfDepth = 1.0;
  const pos = new Vector3(0, die.inradius, 0);
  const vel = new Vector3();
  const spin = new Vector3();
  const quat = new Quaternion();
  const scratch = new Vector3();
  let mode = 'idle';
  let raf = 0;
  let last = 0;
  let settleState = null;

  function lowestPoint() {
    let min = Infinity;
    for (const v of die.vertices) {
      const y = scratch.copy(v).applyQuaternion(quat).y;
      if (y < min) min = y;
    }
    return min;
  }

  function kick() {
    vel.set(rand(-1, 1) * 3, rand(6, 9), rand(-1, 1) * 2);
    spin.set(rand(-1, 1), rand(-1, 1), rand(-1, 1)).normalize().multiplyScalar(rand(10, 16));
  }

  function step(dt) {
    vel.y -= GRAVITY * dt;
    pos.addScaledVector(vel, dt);

    const angle = spin.length() * dt;
    if (angle > 0) {
      const dq = new Quaternion().setFromAxisAngle(scratch.copy(spin).normalize(), angle);
      quat.premultiply(dq).normalize();
    }

    const floor = pos.y + lowestPoint();
    if (floor < 0) {
      pos.y -= floor;
      if (vel.y < 0) vel.y = -vel.y * 0.42;
      vel.x *= 0.82;
      vel.z *= 0.82;
      spin.multiplyScalar(0.78);
      spin.x += rand(-1, 1) * vel.length() * 0.3;
      spin.z += rand(-1, 1) * vel.length() * 0.3;
    }
    if (Math.abs(pos.x) > halfWidth) { pos.x = Math.sign(pos.x) * halfWidth; vel.x *= -0.6; spin.y += rand(-3, 3); }
    if (Math.abs(pos.z) > halfDepth) { pos.z = Math.sign(pos.z) * halfDepth; vel.z *= -0.6; spin.x += rand(-3, 3); }

    if (vel.length() < 0.6 && spin.length() < 1.5 && floor <= 0.01) kick();
  }

  function targetFor(number) {
    const { normal, up } = die.info[number];
    const align = new Quaternion().setFromUnitVectors(normal, new Vector3(0, 1, 0));
    const upAfter = up.clone().applyQuaternion(align);
    const yaw = Math.atan2(upAfter.x, -upAfter.z);
    const twist = new Quaternion().setFromAxisAngle(new Vector3(0, 1, 0), yaw);
    return twist.multiply(align);
  }

  function render() {
    mesh.position.copy(pos);
    mesh.quaternion.copy(quat);
    const lift = Math.max(0, pos.y - die.inradius);
    shadow.position.x = pos.x;
    shadow.position.z = pos.z;
    shadow.scale.setScalar(1 + lift * 0.25);
    shadow.material.opacity = Math.max(0.25, 1 - lift * 0.3);
    renderer.render(scene, camera);
  }

  function frame(now) {
    const dt = Math.min(1 / 30, (now - last) / 1000 || 0);
    last = now;

    if (mode === 'rolling') {
      step(dt);
    } else if (mode === 'settling') {
      const t = Math.min(1, (now - settleState.start) / SETTLE_MS);
      const e = easeOutCubic(t);
      quat.copy(settleState.fromQuat).slerp(settleState.toQuat, e);
      pos.lerpVectors(settleState.fromPos, settleState.toPos, e);
      pos.y += Math.sin(Math.PI * t) * 0.35 * (1 - t);
      if (t >= 1) {
        mode = 'landed';
        settleState.resolve();
      }
    }

    render();
    if (mode === 'rolling' || mode === 'settling') raf = requestAnimationFrame(frame);
  }

  function loop() {
    cancelAnimationFrame(raf);
    last = performance.now();
    raf = requestAnimationFrame(frame);
  }

  quat.copy(targetFor(10));
  render();

  return {
    start() {
      pos.set(-halfWidth * 0.8, 2.5, rand(-0.8, 0.8));
      vel.set(rand(7, 10), rand(2, 4), rand(-2, 2));
      spin.set(rand(-1, 1), rand(-1, 1), rand(-1, 1)).normalize().multiplyScalar(rand(14, 20));
      quat.setFromAxisAngle(new Vector3(rand(-1, 1), rand(-1, 1), rand(-1, 1)).normalize(), rand(0, Math.PI * 2));
      mode = 'rolling';
      loop();
    },
    settle(number) {
      return new Promise((resolve) => {
        settleState = {
          start: performance.now(),
          fromQuat: quat.clone(),
          toQuat: targetFor(number),
          fromPos: pos.clone(),
          toPos: new Vector3(Math.max(-1, Math.min(1, pos.x)) * 0.5, die.inradius, Math.max(-0.5, Math.min(0.5, pos.z)) * 0.5),
          resolve,
        };
        mode = 'settling';
        loop();
      });
    },
    show(number) {
      quat.copy(targetFor(number));
      pos.set(0, die.inradius, 0);
      mode = 'landed';
      render();
    },
    stop() {
      mode = 'idle';
      cancelAnimationFrame(raf);
    },
    dispose() {
      cancelAnimationFrame(raf);
      renderer.dispose();
      materials.forEach((m) => { m.map.dispose(); m.dispose(); });
      die.geometry.dispose();
      renderer.domElement.remove();
    },
  };
}
