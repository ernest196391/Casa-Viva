"""Read-only preflight for a disposable E3 systemd probe; never launches it."""
import os
import re
import stat
from pathlib import Path

ROOT = Path('/var/lib/control-os-dev/work')
LEAF = re.compile(r'run-e3-[A-Za-z0-9][A-Za-z0-9_-]{0,47}\Z')
CANARY = b'cv-e3-public-synthetic-canary\n'


def _check_dir(fd, owner, mode):
    st = os.fstat(fd)
    if not stat.S_ISDIR(st.st_mode) or st.st_uid != owner or stat.S_IMODE(st.st_mode) != mode:
        raise ValueError('unexpected directory owner or permissions')


def _open_dir(name, parent=None):
    return os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)


def _inspect(root_fd, workspace, sibling, trusted_uid, worker_uid):
    if workspace == sibling or not all(isinstance(n, str) and LEAF.fullmatch(n) for n in (workspace, sibling)):
        raise ValueError('invalid synthetic workspace name')
    _check_dir(root_fd, trusted_uid, 0o755)
    wfd = _open_dir(workspace, root_fd)
    try:
        _check_dir(wfd, worker_uid, 0o700)
        if os.listdir(wfd):
            raise ValueError('synthetic workspace must be empty')
        sfd = _open_dir(sibling, root_fd)
        try:
            _check_dir(sfd, trusted_uid, 0o755)
            before = os.stat('canary', dir_fd=sfd, follow_symlinks=False)
            if not stat.S_ISREG(before.st_mode):
                raise ValueError('canary must be a regular file')
            fd = os.open('canary', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=sfd)
            try:
                st = os.fstat(fd)
                if (not stat.S_ISREG(st.st_mode) or st.st_uid != trusted_uid
                    or (st.st_dev, st.st_ino) != (before.st_dev, before.st_ino)
                    or stat.S_IMODE(st.st_mode) != 0o644 or st.st_nlink != 1
                    or st.st_size != len(CANARY) or os.read(fd, len(CANARY)+1) != CANARY):
                    raise ValueError('invalid synthetic canary')
            finally:
                os.close(fd)
        finally:
            os.close(sfd)
    finally:
        os.close(wfd)


def inspect_probe(workspace, sibling):
    """Fail closed unless all ancestors are root-controlled, non-symlink directories.

    A successful preflight is only a snapshot, not authorization or certification.
    The supervisor must separately exclude concurrent changes and recheck before launch.
    """
    import pwd
    paths = [Path(p) for p in (workspace, sibling)]
    if any(str(p) != raw or p.parent != ROOT for p, raw in zip(paths, (workspace, sibling))):
        raise ValueError('not a canonical synthetic path')
    fd = _open_dir('/')
    try:
        for part in ROOT.parts[1:]:
            st = os.fstat(fd)
            if st.st_uid != 0 or st.st_mode & 0o022:
                raise ValueError('untrusted ancestor')
            child = _open_dir(part, fd)
            os.close(fd)
            fd = child
        _inspect(fd, paths[0].name, paths[1].name, 0, pwd.getpwnam('cvdev').pw_uid)
    finally:
        os.close(fd)
